<?php
/* ============================================================
   LÀM BÀI THI THEO MA TRẬN — single-file app
   Đăng nhập: Tên đăng nhập = Số báo danh (tự do)
              Mật khẩu      = Mã ma trận (VD: MT-2026-01)
   ============================================================ */
session_start();

/* ================= TỰ NHẬN DIỆN MÔI TRƯỜNG ================= */
define('BUNDLE_DIR',  __DIR__ . '/bundle-dat');
define('BUNDLE_FILE', BUNDLE_DIR . '/bundle.dat');

function bundle_co_ton_tai() {
    if (is_file(BUNDLE_FILE)) return true;
    if (glob(BUNDLE_DIR . '/bundle*.dat.part*')) return true;
    return false;
}
// true  = máy thi (có bundle.dat)  -> giải mã & dùng dữ liệu trong bundle
// false = máy thường (không có)    -> dùng thẳng /data như cũ
define('DUNG_BUNDLE', bundle_co_ton_tai());

define('BASE_URL', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')); // dùng khi KHÔNG chạy bundle

if (DUNG_BUNDLE) {
    /* ================= NẠP GÓI ĐỀ THI ĐÃ MÃ HOÁ (bundle.dat) ================= */
    require_once __DIR__ . '/bundle_crypto.php'; // file phụ cố định, chỉ tồn tại trên máy thi

    define('BUNDLE_WORKDIR', BUNDLE_DIR . '/_cache_' . hash('sha256', BUNDLE_FILE)); // <-- SỬA: tránh phụ thuộc /tmp dễ bị dọn

    function bundle_rrmdir($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? bundle_rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }
    function bundle_list_sources() {
        if (is_file(BUNDLE_FILE)) return [BUNDLE_FILE];
        $parts = glob(BUNDLE_DIR . '/bundle*.dat.part*');
        if (!$parts) return [];
        natsort($parts);
        return array_values($parts);
    }
    function bundle_sources_signature(array $sources) {
        $sig = '';
        foreach ($sources as $p) {
            clearstatcache(true, $p);
            $sig .= $p . ':' . filemtime($p) . ':' . filesize($p) . '|';
        }
        return hash('sha256', $sig);
    }
    function bundle_read_raw(array $sources) {
        $raw = '';
        foreach ($sources as $p) { $raw .= file_get_contents($p); }
        return $raw;
    }
    function bundle_load_if_needed() {
        $sources = bundle_list_sources();
        if (!$sources) throw new RuntimeException('Chưa có file bundle.dat (hoặc bundle*.dat.part*) cạnh thi.php.');
        if (!is_dir(BUNDLE_WORKDIR)) @mkdir(BUNDLE_WORKDIR, 0700, true);

        $sig    = bundle_sources_signature($sources);
        $marker = BUNDLE_WORKDIR . '/.unpacked';
        if (is_file($marker) && trim((string)file_get_contents($marker)) === $sig) return;

        $lf = fopen(BUNDLE_WORKDIR . '/.lock', 'c');
        if ($lf === false) throw new RuntimeException('Không mở được file khoá trong ' . BUNDLE_WORKDIR . ' — kiểm tra quyền ghi thư mục tạm.'); // <-- THÊM MỚI
        flock($lf, LOCK_EX);
        try {                                                                          // <-- THÊM MỚI: bọc try/finally để luôn mở khoá dù lỗi
            if (is_file($marker) && trim((string)file_get_contents($marker)) === $sig) return;

            // ==== THÊM MỚI: thử lại tối đa 2 lần, tránh rớt vì lỗi I/O vãng lai (đọc file đang được ghi dở, disk bận...) ====
            $lastErr = null;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $raw   = bundle_read_raw($sources);
                    $plain = bundle_decrypt($raw, 'exam-bundle-v1');
                    $raw   = null;

                    $tmpZip = BUNDLE_WORKDIR . '/pack_' . bin2hex(random_bytes(8)) . '.zip';
                    file_put_contents($tmpZip, $plain);
                    $plain = null;

                    $zip = new ZipArchive();
                    $openRc = $zip->open($tmpZip);
                    if ($openRc !== true) throw new RuntimeException('ZipArchive::open lỗi, mã: ' . $openRc);

                    // ==== SỬA: giải nén ra thư mục tạm riêng trước, chỉ thay thế data/uploads cũ SAU KHI chắc chắn giải nén thành công
                    $tmpExtract = BUNDLE_WORKDIR . '/_new_' . bin2hex(random_bytes(4));
                    @mkdir($tmpExtract, 0700, true);
                    if (!$zip->extractTo($tmpExtract)) { $zip->close(); throw new RuntimeException('Giải nén zip thất bại.'); }
                    $zip->close();
                    @unlink($tmpZip);

                    bundle_rrmdir(BUNDLE_WORKDIR . '/data');
                    bundle_rrmdir(BUNDLE_WORKDIR . '/uploads');
                    @rename($tmpExtract . '/data', BUNDLE_WORKDIR . '/data');
                    @rename($tmpExtract . '/uploads', BUNDLE_WORKDIR . '/uploads');
                    bundle_rrmdir($tmpExtract);

                    file_put_contents($marker, $sig);
                    @chmod(BUNDLE_WORKDIR, 0700);
                    return; // thành công, thoát khỏi hàm
                } catch (Throwable $e) {
                    $lastErr = $e;
                    error_log('[bundle_load_if_needed] lần thử ' . $attempt . ' lỗi: ' . $e->getMessage()); // <-- THÊM MỚI
                    usleep(200000); // đợi 0.2s rồi thử lại lần nữa
                }
            }
            throw $lastErr; // cả 2 lần đều lỗi mới thực sự báo lỗi ra ngoài
        } finally {
            flock($lf, LOCK_UN); fclose($lf);
        }
    }
    try { bundle_load_if_needed(); }
    catch (Throwable $e) {
        error_log('[bundle_load_if_needed] ' . $e->getMessage()); // <-- THÊM MỚI: ghi log thật ra error_log của PHP để xem nguyên nhân
        http_response_code(500);
        die('Không thể tải dữ liệu đề thi. ' . (php_sapi_name()==='cli' ? $e->getMessage() : ''));
    }

    $DATA_ROOT    = BUNDLE_WORKDIR . '/data';
    $UPLOADS_ROOT = BUNDLE_WORKDIR . '/uploads';
} else {
    /* ================= DÙNG DỮ LIỆU TRỰC TIẾP TRONG /data (như file bình thường) ================= */
    $DATA_ROOT    = __DIR__ . '/data';
    $UPLOADS_ROOT = __DIR__; // ảnh/audio lưu đường dẫn tương đối tính từ thư mục gốc (ví dụ uploads/xxx.png)
}

define('QDB_PATH',           $DATA_ROOT . '/question_bank.sqlite');
define('MATRIX_DB_PATH',     $DATA_ROOT . '/exam_matrix.sqlite');
define('THI_DB_PATH',        __DIR__ . '/data/phien_thi.sqlite'); // luôn nằm ngoài bundle
define('USER_DB_PATH',       $DATA_ROOT . '/users.sqlite');
define('TOT_NGHIEP_DB_PATH', $DATA_ROOT . '/tot_nghiep_bank.sqlite');
define('LAB_DB_PATH',        $DATA_ROOT . '/lab_values.sqlite');

$CATEGORIES = [
    'he_mien_dich'               => 'Hệ Miễn dịch',
    'he_mau_luoi_bach_huyet'     => 'Hệ Máu - Lưới - Bạch huyết',
    'suc_khoe_hanh_vi_tam_than'  => 'Sức khỏe hành vi - Tâm thần',
    'he_than_kinh_giac_quan'     => 'Hệ thần kinh và các giác quan',
    'da_mo_duoi_da'              => 'Da - mô dưới da',
    'he_co_xuong_khop'           => 'Hệ cơ xương khớp',
    'he_tim_mach'                => 'Hệ tim mạch',
    'he_ho_hap'                  => 'Hệ hô hấp',
    'he_tieu_hoa'                => 'Hệ tiêu hóa',
    'he_than_nieu'               => 'Hệ thận - niệu',
    'thai_ky_chuyen_da_sinh_de'  => 'Thai kỳ, Chuyển dạ, Sinh đẻ và Hậu sản',
    'he_sinh_duc_vu_sk_sinh_san' => 'Hệ sinh dục, vú và sức khoẻ sinh sản',
    'he_noi_tiet_dinh_duong'     => 'Hệ nội tiết - dinh dưỡng - chuyển hóa',
    'roi_loan_da_co_quan'        => 'Rối loạn đa cơ quan',
    'y_hoc_cap_cuu'              => 'Y học cấp cứu',
    'thong_ke_y_sinh_dich_te'    => 'Thống kê y sinh học, Dịch tễ học/Sức khoẻ dân số và Diễn giải y văn',
    'khoa_hoc_xa_hoi_y_hoc'      => 'Khoa học xã hội trong y học',
];

function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function tableName($catKey) {
    global $CATEGORIES;
    return isset($CATEGORIES[$catKey]) ? 'cauhoi_' . $catKey : null;
}

function getQPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . QDB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}
function getMatrixPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . MATRIX_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}
function getThiPDO() {
    static $pdo = null;
    if ($pdo === null) {
        if (!is_dir(dirname(THI_DB_PATH))) mkdir(dirname(THI_DB_PATH), 0777, true);
        $pdo = new PDO('sqlite:' . THI_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS phien_thi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sbd TEXT NOT NULL,
            matran_id INTEGER NOT NULL,
            ma_matran TEXT NOT NULL,
            ten_matran TEXT,
            ma_de TEXT NOT NULL,
            questions_json TEXT NOT NULL,
            answers_json TEXT NOT NULL DEFAULT '{}',
            thoi_gian_phut INTEGER NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            submitted INTEGER NOT NULL DEFAULT 0,
            submitted_at TEXT,
            total_cau INTEGER NOT NULL DEFAULT 0,
            so_cau_dung INTEGER,
            score REAL,
            created_at TEXT
        )");
        // ==== THÊM MỚI: đánh dấu phiên thi thuộc nguồn nào (yk = Y khoa, tn = Tốt nghiệp) ====
        try { $pdo->exec("ALTER TABLE phien_thi ADD COLUMN nguon_de TEXT NOT NULL DEFAULT 'yk'"); } catch (Exception $e) {}
        // ==== THÊM MỚI: đánh dấu chế độ làm bài (thi = thi thật, luyentap = luyện tập hiện đáp án ngay) ====
        try { $pdo->exec("ALTER TABLE phien_thi ADD COLUMN che_do TEXT NOT NULL DEFAULT 'thi'"); } catch (Exception $e) {}
        // ==== THÊM MỚI: lưu IP máy làm bài để admin bên matran_ao.php xem ====
        try { $pdo->exec("ALTER TABLE phien_thi ADD COLUMN ip TEXT"); } catch (Exception $e) {}
    }
    return $pdo;
}

function getUserPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . USER_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function getTotNghiepPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . TOT_NGHIEP_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}
function getLabPDO() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . LAB_DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}
if (DUNG_BUNDLE && ($_GET['action'] ?? '') === 'secure_asset') {
    if (empty($_SESSION['thi_sid'])) { http_response_code(403); exit; }

    $rel = str_replace(['..', '\\'], '', $_GET['f'] ?? '');
    $rel = ltrim($rel, '/');
    if (str_starts_with($rel, 'uploads/')) $rel = substr($rel, strlen('uploads/'));
    $full = $UPLOADS_ROOT . '/' . $rel;

    if (!is_file($full)) { http_response_code(404); exit; }
    $mime = ['png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
             'mp3'=>'audio/mpeg','wav'=>'audio/wav','ogg'=>'audio/ogg','m4a'=>'audio/mp4']
            [strtolower(pathinfo($full, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=600');
    readfile($full);
    exit;
}
function assetUrl($relPath) {
    if (!$relPath) return null;
    if (preg_match('#^https?://#i', $relPath)) return $relPath; // đã là URL tuyệt đối thì giữ nguyên
    $relPath = ltrim($relPath, '/');
    if (DUNG_BUNDLE) {
        return '?action=secure_asset&f=' . rawurlencode($relPath);
    }
    return BASE_URL . '/' . $relPath;
}
function fetchAllLabTables() {
    try {
        $rows = getLabPDO()->query("SELECT id, ten_bang, ghi_chu, data_json FROM bang_xet_nghiem ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC); // <-- SỬA: thêm ghi_chu
    } catch (Exception $e) { return []; }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'ten_bang' => $r['ten_bang'],
            'ghi_chu' => $r['ghi_chu'] ?? '', // <-- THÊM
            'data' => json_decode($r['data_json'], true) ?: [],
        ];
    }
    return $out;
}

function fetchQuestionFull($catKey, $id) {
    $table = tableName($catKey);
    if (!$table) return null;
    $stmt = getQPDO()->prepare("SELECT * FROM `$table` WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['choices']    = json_decode($row['choices_json'] ?? '[]', true) ?: [];
        $row['references'] = json_decode($row['references_json'] ?? '[]', true) ?: [];
        $row['ausculta']   = json_decode($row['ausculta_json'] ?? '', true) ?: null;
        $row['xetnghiem']  = json_decode($row['xetnghiem_json'] ?? '', true) ?: null;   // <-- THÊM MỚI
    }
    return $row;
}

/* ==== THÊM MỚI: lấy 1 câu đơn bên Tốt nghiệp ==== */
function fetchTotNghiepCauHoi($id) {
    $stmt = getTotNghiepPDO()->prepare("SELECT * FROM cauhoi WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) $row['dap_an'] = json_decode($row['dap_an_json'], true) ?: [];
    return $row;
}

/* ==== THÊM MỚI: lấy toàn bộ ý hỏi trong 1 nhóm case study (theo case_group) ==== */
function fetchTotNghiepGroup($caseGroup) {
    $stmt = getTotNghiepPDO()->prepare("SELECT * FROM cauhoi WHERE case_group=? ORDER BY ma_cau ASC, id ASC");
    $stmt->execute([$caseGroup]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['dap_an'] = json_decode($r['dap_an_json'], true) ?: [];
    unset($r);
    return $rows;
}

/* Chọn ngẫu nhiên đơn vị câu hỏi bên Tốt nghiệp theo Nội dung/Chương + độ khó.
   1 NHÓM CASE STUDY LUÔN TÍNH LÀ 1 ĐƠN VỊ — không còn phụ thuộc tên chương,
   nên nhóm case có thể nằm ở bất kỳ chương nào (kể cả chương thường) vẫn được
   chọn và trộn đúng với các câu đơn cùng chương khi ra đề. */
function pickRandomTotNghiepIds($noidungId, $chuongId, $cheDoGop, $difficulty, $count, &$excludedSingle, &$excludedGroup) {
    if ($count <= 0) return [];
    $pdo = getTotNghiepPDO();

    if ($chuongId) {
        $scopeSql = "chuong_id=?"; $scopeParams = [$chuongId];                 // chi tiết từng chương
    } elseif ($cheDoGop) {
        $scopeSql = "(chuong_id IS NULL OR chuong_id NOT IN (SELECT id FROM chuong WHERE noidung_id=? AND ten_chuong='Case Study'))";
        $scopeParams = [$noidungId];                                            // gộp nội dung, trừ chương Case Study (đã có dòng riêng)
    } else {
        $scopeSql = "chuong_id IS NULL"; $scopeParams = [];                     // chỉ câu cấp nội dung
    }

    $units = [];

    // 1) Các nhóm case study trong phạm vi này -> mỗi nhóm = 1 đơn vị
    $stmt = $pdo->prepare("SELECT DISTINCT case_group FROM cauhoi
        WHERE noidung_id=? AND $scopeSql AND case_group IS NOT NULL AND case_group<>''");
    $stmt->execute(array_merge([$noidungId], $scopeParams));
    foreach (array_diff($stmt->fetchAll(PDO::FETCH_COLUMN), $excludedGroup) as $g) {
        $chk = $pdo->prepare("SELECT do_kho FROM cauhoi WHERE case_group=? ORDER BY ma_cau ASC, id ASC LIMIT 1");
        $chk->execute([$g]);
        $doKho = $chk->fetchColumn();
        if ($difficulty === null || $doKho === $difficulty) {
            $units[] = ['type' => 'group', 'case_group' => $g];
        }
    }

    // 2) Câu đơn (không thuộc nhóm case nào) trong cùng phạm vi này
    $stmt = $pdo->prepare("SELECT id, do_kho FROM cauhoi
        WHERE noidung_id=? AND $scopeSql AND (case_group IS NULL OR case_group='')");
    $stmt->execute(array_merge([$noidungId], $scopeParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['id'];
        if (in_array($id, $excludedSingle, true)) continue;
        if ($difficulty !== null && $r['do_kho'] !== $difficulty) continue;
        $units[] = ['type' => 'single', 'id' => $id];
    }

    shuffle($units);
    $picked = array_slice($units, 0, (int)$count);
    foreach ($picked as $u) {
        if ($u['type'] === 'group') $excludedGroup[] = $u['case_group'];
        else $excludedSingle[] = $u['id'];
    }
    return $picked;
}

/* ==== THÊM MỚI: chuẩn hoá câu đơn Tốt nghiệp sang định dạng gửi cho JS ==== */
function chuanHoaCauTNChoLucThi($row) {
    $choices = [];
    foreach (($row['dap_an'] ?? []) as $c) $choices[] = ['option_id' => $c['option_id'] ?? '', 'text' => $c['text'] ?? ''];
    return [
        'kind'       => 'single',
        'loai_cau'   => $row['loai_cau'] ?? 'mot_dap_an',   // <-- THÊM MỚI
        'vignette'   => '',
        'lead_in'    => $row['cau_hoi'] ?? '',
        'image_path' => assetUrl($row['hinh_anh'] ?? null),
        'choices'    => $choices,
    ];
}

/* ==== THÊM MỚI: giống chuanHoaCauTNChoLucThi nhưng kèm đáp án đúng + giải thích (dùng cho chế độ luyện tập) ==== */
function chuanHoaCauTNChoLuyenTap($row) {
    $choices = []; $correct = null;
    foreach (($row['dap_an'] ?? []) as $c) {
        $choices[] = ['option_id' => $c['option_id'] ?? '', 'text' => $c['text'] ?? '', 'is_correct' => !empty($c['is_correct'])];
        if (!empty($c['is_correct'])) $correct = $c['option_id'];
    }
    return [
        'kind'              => 'single',
        'loai_cau'          => $row['loai_cau'] ?? 'mot_dap_an',
        'vignette'          => '',
        'lead_in'           => $row['cau_hoi'] ?? '',
        'image_path'        => assetUrl($row['hinh_anh'] ?? null),
        'choices'           => $choices,
        'correct_option_id' => $correct,
        'giai_thich'        => $row['giai_thich'] ?? '',
    ];
}
/* ==== THÊM MỚI: chuẩn hoá cả nhóm case study cho chế độ luyện tập (kèm đáp án đúng + giải thích từng ý) ==== */
function chuanHoaNhomTNChoLuyenTap($rows) {
    $parts = [];
    foreach ($rows as $r) $parts[] = chuanHoaCauTNChoLuyenTap($r);
    return ['kind' => 'group', 'group_stem' => '', 'parts' => $parts];
}

/* ==== THÊM MỚI: chấm 1 câu Tốt nghiệp theo đúng loại câu hỏi ==== */
function chamCauTotNghiep($row, $userAnswer) {
    $loaiCau = $row['loai_cau'] ?? 'mot_dap_an';
    $dapAn   = $row['dap_an'] ?? [];

    if ($loaiCau === 'nhieu_dap_an') {
        // Phải chọn ĐỦ và KHÔNG THỪA các đáp án đúng mới tính đúng
        $correctSet = [];
        foreach ($dapAn as $c) if (!empty($c['is_correct'])) $correctSet[] = $c['option_id'];
        sort($correctSet);
        $userSet = is_array($userAnswer) ? array_values(array_unique($userAnswer)) : [];
        sort($userSet);
        return !empty($correctSet) && $correctSet === $userSet;
    }

    if ($loaiCau === 'dung_sai') {
        // Mỗi phương án phải đánh đúng Đúng/Sai; sai 1 ý là cả câu tính sai
        if (!is_array($userAnswer) || empty($dapAn)) return false;
        foreach ($dapAn as $c) {
            $optId = $c['option_id'];
            $correctBool = !empty($c['is_correct']);
            $userVal = $userAnswer[$optId] ?? null;
            if ($userVal === null) return false;
            $userBool = ($userVal === 'dung');
            if ($userBool !== $correctBool) return false;
        }
        return true;
    }

    // mot_dap_an (mặc định, giữ nguyên logic cũ)
    $correct = null;
    foreach ($dapAn as $c) if (!empty($c['is_correct'])) { $correct = $c['option_id']; break; }
    return $correct !== null && $userAnswer === $correct;
}

/* ==== THÊM MỚI: chuẩn hoá cả nhóm case study sang định dạng gửi cho JS (1 câu, nhiều ý) ==== */
function chuanHoaNhomTNChoLucThi($rows) {
    $parts = [];
    foreach ($rows as $r) $parts[] = chuanHoaCauTNChoLucThi($r);
    return ['kind' => 'group', 'group_stem' => '', 'parts' => $parts];
}

// <-- THÊM HÀM MỚI: chuẩn hoá đường dẫn ảnh/audio thành URL đầy đủ để gửi ra JS
function chuanHoaAusculta($ausculta) {
    if (!$ausculta || empty($ausculta['image']['duong_dan'])) return null;
    $out = $ausculta;
    $out['image']['duong_dan'] = assetUrl($ausculta['image']['duong_dan']);
    foreach ($out['points'] as &$p) {
        if (!empty($p['audio_chuong'])) $p['audio_chuong'] = assetUrl($p['audio_chuong']);
        if (!empty($p['audio_mang']))   $p['audio_mang']   = assetUrl($p['audio_mang']);
    }
    unset($p);
    return $out;
}

/* Chọn ngẫu nhiên $count id trong $table theo độ khó (null = không lọc độ khó),
   loại trừ những id đã chọn trước đó (&$excluded được cập nhật luôn) */
function pickRandomIds($table, $difficulty, $count, &$excluded) {
    if ($count <= 0) return [];
    $pdo = getQPDO();
    $sql = "SELECT id FROM `$table`";
    $where = []; $params = [];
    if ($difficulty !== null) { $where[] = "difficulty = ?"; $params[] = $difficulty; }
    if (!empty($excluded)) {
        $ph = implode(',', array_fill(0, count($excluded), '?'));
        $where[] = "id NOT IN ($ph)";
        $params = array_merge($params, $excluded);
    }
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    shuffle($ids);
    $picked = array_slice($ids, 0, (int)$count);
    $excluded = array_merge($excluded, $picked);
    return $picked;
}

function generateMaDe($matranId, $nguonDe) {   // <-- thêm tham số $nguonDe
    $tpdo = getThiPDO();
    do {
        $code = (string)random_int(100, 999);
        $stmt = $tpdo->prepare("SELECT COUNT(*) FROM phien_thi WHERE matran_id=? AND nguon_de=? AND ma_de=?");
        $stmt->execute([$matranId, $nguonDe, $code]);
    } while ((int)$stmt->fetchColumn() > 0);
    return $code;
}

function fetchPhien($id) {
    $stmt = getThiPDO()->prepare("SELECT * FROM phien_thi WHERE id=?");
    $stmt->execute([$id]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function isExpired($phien) { return strtotime($phien['end_time']) <= time(); }

/* Chấm điểm & đóng phiên thi (idempotent) */
function chamDiemVaNopBai($phien) {
    if ((int)$phien['submitted'] === 1) return $phien;
    $questions = json_decode($phien['questions_json'], true) ?: [];
    $answers   = json_decode($phien['answers_json'], true) ?: [];
    $dungCau = 0; $dungY = 0; $tongY = 0;

    foreach ($questions as $i => $q) {
        $src = $q['src'] ?? 'yk';

        if ($src === 'tn' && ($q['type'] ?? '') === 'group') {
            $rows = fetchTotNghiepGroup($q['case_group']);
            $soY = count($rows); $soYDung = 0;
            foreach ($rows as $j => $r) {
                $key = $i . '_' . $j;
                if (chamCauTotNghiep($r, $answers[$key] ?? null)) $soYDung++;   // <-- SỬA
            }
            $tongY += $soY; $dungY += $soYDung;
            if ($soY > 0 && $soYDung === $soY) $dungCau++;
        } elseif ($src === 'tn') {
            $row = fetchTotNghiepCauHoi($q['id']);
            $tongY++;
            if ($row && chamCauTotNghiep($row, $answers[(string)$i] ?? null)) { $dungY++; $dungCau++; }  // <-- SỬA
        } else {
            $row = fetchQuestionFull($q['cat'], $q['id']);
            $tongY++;
            if ($row && isset($answers[(string)$i]) && $answers[(string)$i] === $row['correct_option_id']) { $dungY++; $dungCau++; }
        }
    }

    $score = $tongY > 0 ? round($dungY / $tongY * 10, 2) : 0;
    getThiPDO()->prepare("UPDATE phien_thi SET submitted=1, submitted_at=datetime('now'), so_cau_dung=?, score=? WHERE id=?")
        ->execute([$dungCau, $score, $phien['id']]);
    return fetchPhien($phien['id']);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$requestMode = (($_GET['mode'] ?? $_POST['mode'] ?? '') === 'luyentap') ? 'luyentap' : 'thi';

/* ================= ĐĂNG XUẤT ================= */
if ($action === 'logout') {
    $modeGiuLai = $requestMode; // mặc định lấy theo request hiện tại (phòng khi không có session)
    if (!empty($_SESSION['thi_sid'])) {
        $pLogout = fetchPhien((int)$_SESSION['thi_sid']);
        if ($pLogout) {
            if ((int)$pLogout['submitted'] === 0) {
                chamDiemVaNopBai($pLogout);
            }
            $modeGiuLai = $pLogout['che_do'] ?? $modeGiuLai; // <-- THÊM: lấy đúng chế độ của phiên vừa thoát
        }
    }
    unset($_SESSION['thi_sid']);
    header('Location: ?' . ($modeGiuLai === 'luyentap' ? 'mode=luyentap' : '')); // <-- SỬA: giữ mode trên URL
    exit;
}

/* ================= LƯU ĐÁP ÁN (AJAX) ================= */
if ($action === 'save_answer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int)($_POST['sid'] ?? 0);
    $idx = $_POST['idx'] ?? null;
    $raw = $_POST['option_id'] ?? '';                                    // <-- SỬA tên biến
    $phien = fetchPhien($sid);
    if (!$phien || (int)$phien['submitted'] === 1 || empty($_SESSION['thi_sid']) || (int)$_SESSION['thi_sid'] !== $sid) {
        echo json_encode(['ok' => false]); exit;
    }
    if (isExpired($phien)) {
        chamDiemVaNopBai($phien);
        echo json_encode(['ok' => false, 'expired' => true]); exit;
    }
    // <-- THÊM MỚI: hỗ trợ đáp án dạng mảng (nhiều đáp án đúng) / object (đúng-sai) gửi lên dạng JSON
    $decoded = json_decode($raw, true);
    $opt = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw;

    $answers = json_decode($phien['answers_json'], true) ?: [];
    $answers[(string)$idx] = $opt;
    getThiPDO()->prepare("UPDATE phien_thi SET answers_json=? WHERE id=?")
        ->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), $sid]);
    echo json_encode(['ok' => true]); exit;
}

/* ================= NỘP BÀI ================= */
if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid = (int)($_POST['sid'] ?? 0);
    $phien = fetchPhien($sid);
    if ($phien && !empty($_SESSION['thi_sid']) && (int)$_SESSION['thi_sid'] === $sid) {
        chamDiemVaNopBai($phien);
    }
    header('Location: ?action=result'); exit;
}

/* ================= ĐĂNG NHẬP ================= */
$loginErr = '';
if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $sbd = trim($_POST['sbd'] ?? '');
    $mk  = trim($_POST['password'] ?? '');
    if ($sbd === '' || $mk === '') {
        $loginErr = 'Vui lòng nhập đầy đủ Số báo danh và Mật khẩu.';
    } else {
        // Kiểm tra số báo danh có tồn tại không
        $ustmt = getUserPDO()->prepare("SELECT id FROM users WHERE so_bao_danh = ?");
        $ustmt->execute([$sbd]);
        if (!$ustmt->fetch()) {
            $loginErr = 'Số báo danh không tồn tại trong hệ thống.';
        } else {
            // ==== THAY ĐOẠN "$mstmt = getMatrixPDO()->prepare(...)" TRỞ XUỐNG ====
            $mstmt = getMatrixPDO()->prepare("SELECT * FROM matran WHERE ma_matran = ?");
            $mstmt->execute([$mk]);
            $matran = $mstmt->fetch(PDO::FETCH_ASSOC);
            $nguonDe = 'yk';

            if (!$matran) {
                // ==== THÊM MỚI: không thấy bên Y khoa -> thử tìm bên Tốt nghiệp ====
                $tnStmt = getTotNghiepPDO()->prepare("SELECT * FROM matran WHERE ma_matran = ?");
                $tnStmt->execute([$mk]);
                $matran = $tnStmt->fetch(PDO::FETCH_ASSOC);
                if ($matran) $nguonDe = 'tn';
            }

            if (!$matran) {
                $loginErr = 'Mật khẩu (mã ma trận) không đúng.';
            } else {
                $tpdo = getThiPDO();
                $stmt = $tpdo->prepare("SELECT * FROM phien_thi WHERE sbd=? AND matran_id=? AND nguon_de=? AND che_do=? AND submitted=0 ORDER BY id DESC LIMIT 1"); // <-- SỬA: thêm che_do
                $stmt->execute([$sbd, $matran['id'], $nguonDe, $requestMode]);   // <-- SỬA: thêm $requestMode
                $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                $muonDeMoi = !empty($_POST['newde']);
                if ($existing && !isExpired($existing) && !$muonDeMoi) {
                    $_SESSION['thi_sid'] = (int)$existing['id'];
                    header('Location: ?'); exit;
                }
                if ($existing && isExpired($existing)) chamDiemVaNopBai($existing);

                $allQ = [];

                if ($nguonDe === 'tn') {
                    // ==== THÊM MỚI: build đề từ ngân hàng Tốt nghiệp ====
                    $cheDoGop = !empty($matran['che_do_gop']);
                    $ctStmt = getTotNghiepPDO()->prepare("SELECT * FROM matran_chitiet WHERE matran_id=?");
                    $ctStmt->execute([$matran['id']]);
                    foreach ($ctStmt->fetchAll(PDO::FETCH_ASSOC) as $ct) {
                        $excludedSingle = []; $excludedGroup = [];
                        $items = [];
                        $items = array_merge($items, pickRandomTotNghiepIds($ct['noidung_id'], $ct['chuong_id'], $cheDoGop, 'dễ', (int)$ct['so_cau_de'], $excludedSingle, $excludedGroup));
                        $items = array_merge($items, pickRandomTotNghiepIds($ct['noidung_id'], $ct['chuong_id'], $cheDoGop, 'trung bình', (int)$ct['so_cau_tb'], $excludedSingle, $excludedGroup));
                        $items = array_merge($items, pickRandomTotNghiepIds($ct['noidung_id'], $ct['chuong_id'], $cheDoGop, 'khó', (int)$ct['so_cau_kho'], $excludedSingle, $excludedGroup));
                        $items = array_merge($items, pickRandomTotNghiepIds($ct['noidung_id'], $ct['chuong_id'], $cheDoGop, null, (int)$ct['so_cau_tong'], $excludedSingle, $excludedGroup));
                        foreach ($items as $it) $allQ[] = array_merge(['src' => 'tn'], $it);
                    }
                } else {
                    // ---- giữ nguyên toàn bộ đoạn build đề Y khoa hiện có, chỉ thêm 'src'=>'yk' ----
                    $ctStmt = getMatrixPDO()->prepare("SELECT * FROM matran_chitiet WHERE matran_id=?");
                    $ctStmt->execute([$matran['id']]);
                    foreach ($ctStmt->fetchAll(PDO::FETCH_ASSOC) as $ct) {
                        $table = tableName($ct['cat_key']);
                        if (!$table) continue;
                        $excluded = []; $ids = [];
                        $ids = array_merge($ids, pickRandomIds($table, 'dễ', (int)$ct['so_cau_de'], $excluded));
                        $ids = array_merge($ids, pickRandomIds($table, 'trung bình', (int)$ct['so_cau_trungbinh'], $excluded));
                        $ids = array_merge($ids, pickRandomIds($table, 'khó', (int)$ct['so_cau_kho'], $excluded));
                        $ids = array_merge($ids, pickRandomIds($table, null, (int)$ct['so_cau_theo_noidung'], $excluded));
                        foreach ($ids as $qid) $allQ[] = ['src' => 'yk', 'cat' => $ct['cat_key'], 'id' => (int)$qid];
                    }
                }

                if (empty($allQ)) {
                    $loginErr = 'Ma trận này chưa được cấu hình số câu hỏi, hoặc ngân hàng câu hỏi chưa đủ dữ liệu.';
                    unset($_SESSION['thi_sid']); // <-- THÊM: để rơi đúng về trang đăng nhập kèm lỗi, không kẹt ở đề cũ
                } else {
                    if ($existing && $muonDeMoi && !isExpired($existing)) {
                        chamDiemVaNopBai($existing); // chỉ đóng phiên cũ SAU KHI chắc chắn tạo được đề mới
                    }
                    shuffle($allQ);
                    $maDe  = generateMaDe($matran['id'], $nguonDe);   // <-- thêm $nguonDe
                    $phut  = (int)($matran['thoi_gian_lam_bai'] ?? 60);
                    $start = time(); $end = $start + $phut * 60;
                    $ins = $tpdo->prepare("INSERT INTO phien_thi
                        (sbd, matran_id, ma_matran, ten_matran, ma_de, questions_json, answers_json, thoi_gian_phut, start_time, end_time, total_cau, nguon_de, che_do, ip, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, datetime('now'))");
                    $ins->execute([
                        $sbd, $matran['id'], $matran['ma_matran'], $matran['ten_matran'], $maDe,
                        json_encode($allQ, JSON_UNESCAPED_UNICODE), '{}', $phut,
                        date('c', $start), date('c', $end), count($allQ), $nguonDe, $requestMode,
                        $_SERVER['REMOTE_ADDR'] ?? '', // <-- THÊM MỚI
                    ]);
                    $_SESSION['thi_sid'] = (int)$tpdo->lastInsertId();
                    header('Location: ?'); exit;
                }
            }
        }
    }
}

/* ================= XÁC ĐỊNH TRẠNG THÁI HIỆN TẠI ================= */
$phien = null;
if (!empty($_SESSION['thi_sid'])) {
    $phien = fetchPhien((int)$_SESSION['thi_sid']);
    if ($phien && !$phien['submitted'] && isExpired($phien)) {
        $phien = chamDiemVaNopBai($phien);
    }
}

if ($phien && (int)$phien['submitted'] === 1) {
    if ($action !== 'result') { header('Location: ?action=result'); exit; }
} elseif ($action === 'result') {
    header('Location: ?'); exit; // chưa nộp thì không xem được kết quả
}

$showLogin  = !$phien;
$showExam   = $phien && !$phien['submitted'];
$viewResult = $phien && $phien['submitted'] ? $phien : null;
$isLuyenTap = $phien ? (($phien['che_do'] ?? 'thi') === 'luyentap') : false; // <-- THÊM MỚI

/* Chuẩn bị dữ liệu câu hỏi cho JS */
$examQuestionsJs = [];
$resultQuestionsJs = [];
$savedAnswers = [];
$labTablesJs = ($showExam || $viewResult) ? fetchAllLabTables() : [];

if ($showExam) {
    $qs = json_decode($phien['questions_json'], true) ?: [];
    $savedAnswers = json_decode($phien['answers_json'], true) ?: [];
    foreach ($qs as $q) {
        $src = $q['src'] ?? 'yk';
        if ($src === 'tn') {
            // ==== THÊM MỚI ====
            if (($q['type'] ?? '') === 'group') {
                $rows = fetchTotNghiepGroup($q['case_group']);
                $examQuestionsJs[] = $isLuyenTap                          // <-- SỬA
                    ? chuanHoaNhomTNChoLuyenTap($rows)
                    : chuanHoaNhomTNChoLucThi($rows);
            } else {
                $row = fetchTotNghiepCauHoi($q['id']);
                $examQuestionsJs[] = $isLuyenTap                          // <-- SỬA
                    ? chuanHoaCauTNChoLuyenTap($row)
                    : chuanHoaCauTNChoLucThi($row);
            }
            continue;
        }
        // ---- giữ nguyên toàn bộ code Y khoa hiện có ----
        $row = fetchQuestionFull($q['cat'], $q['id']);
        $choices = [];
        if ($row) foreach ($row['choices'] as $c) {
            $choices[] = $isLuyenTap
                ? [
                    'option_id'   => $c['option_id'] ?? '',
                    'text'        => $c['text'] ?? '',
                    'is_correct'  => !empty($c['is_correct']),
                    'image'       => !empty($c['image']) ? assetUrl($c['image']) : null,   // THÊM MỚI
                    'explanation' => $c['explanation'] ?? '',                               // THÊM MỚI
                  ]
                : ['option_id' => $c['option_id'] ?? '', 'text' => $c['text'] ?? ''];
        }
        $examQuestionsJs[] = [
            'kind' => 'single',
            'vignette'      => $row['vignette'] ?? '',
            'image_path'    => assetUrl($row['image_path'] ?? null),
            'image_caption' => $row['image_caption'] ?? null,
            'lead_in'       => $row['lead_in'] ?? '',
            'choices'       => $choices,
            'ausculta'      => chuanHoaAusculta($row['ausculta'] ?? null),
            'xetnghiem'     => $row['xetnghiem'] ?? null,
            'correct_option_id'  => $isLuyenTap ? ($row['correct_option_id'] ?? null) : null,
            'giai_thich'         => $isLuyenTap ? ($row['giai_thich'] ?? '') : '',
            'learning_objective' => $isLuyenTap ? ($row['learning_objective'] ?? '') : '',   // THÊM MỚI
        ];
    }
}

if ($viewResult) {
    $qs = json_decode($viewResult['questions_json'], true) ?: [];
    $savedAnswers = json_decode($viewResult['answers_json'], true) ?: [];
    foreach ($qs as $q) {
        $src = $q['src'] ?? 'yk';

        // 1. Nhóm câu hỏi Case Study (Tốt nghiệp)
        if ($src === 'tn' && ($q['type'] ?? '') === 'group') {
            $parts = [];
            foreach (fetchTotNghiepGroup($q['case_group']) as $r) {
                $correct = null; $choices = [];
                foreach (($r['dap_an'] ?? []) as $c) {
                    $choices[] = ['option_id' => $c['option_id'], 'text' => $c['text'], 'is_correct' => !empty($c['is_correct'])];
                    if (!empty($c['is_correct'])) $correct = $c['option_id'];
                }
                $parts[] = [
                    'lead_in'           => $r['cau_hoi'],
                    'loai_cau'          => $r['loai_cau'] ?? 'mot_dap_an',
                    'image_path'        => assetUrl($r['hinh_anh'] ?? null),
                    'choices'           => $choices,
                    'correct_option_id' => $correct,
                    'giai_thich'        => $r['giai_thich'] ?? ''
                ];
            }
            $resultQuestionsJs[] = ['kind' => 'group', 'group_stem' => '', 'parts' => $parts];
            continue;
        }

        // 2. Câu đơn (Tốt nghiệp)
        if ($src === 'tn') {
            $row = fetchTotNghiepCauHoi($q['id']);
            $correct = null; $choices = [];
            if ($row) {
                foreach (($row['dap_an'] ?? []) as $c) {
                    $choices[] = ['option_id' => $c['option_id'], 'text' => $c['text'], 'is_correct' => !empty($c['is_correct'])];
                    if (!empty($c['is_correct'])) $correct = $c['option_id'];
                }
            }
            $resultQuestionsJs[] = $row ? [
                'kind'              => 'single',
                'loai_cau'          => $row['loai_cau'] ?? 'mot_dap_an',
                'vignette'          => '',
                'lead_in'           => $row['cau_hoi'] ?? '',
                'image_path'        => assetUrl($row['hinh_anh'] ?? null),
                'correct_option_id' => $correct,
                'choices'           => $choices,
                'giai_thich'        => $row['giai_thich'] ?? '',
            ] : null;
            continue;
        }

        // 3. Câu hỏi Y khoa gốc
        $row = fetchQuestionFull($q['cat'], $q['id']);
        if ($row && !empty($row['choices'])) {
            foreach ($row['choices'] as &$c) {
                if (!empty($c['image'])) $c['image'] = assetUrl($c['image']);
            }
            unset($c);
        }

        $resultQuestionsJs[] = $row ? [
            'kind'               => 'single',
            'vignette'           => $row['vignette'],
            'image_path'         => assetUrl($row['image_path'] ?? null),
            'image_caption'      => $row['image_caption'],
            'lead_in'            => $row['lead_in'],
            'learning_objective' => $row['learning_objective'] ?? '',
            'correct_option_id'  => $row['correct_option_id'],
            'choices'            => $row['choices'],
            'ausculta'           => chuanHoaAusculta($row['ausculta'] ?? null),
            'xetnghiem'          => $row['xetnghiem'] ?? null,
        ] : null;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,viewport-fit=cover">
<title>Y dược</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#1a237e">
<style>
:root{ --primary:#1a237e; --brand:#283593; --success:#2e7d32; --danger:#c62828; --warning:#f57c00; --bg:#f0f2f5; --border:#e0e0e0; }
*{box-sizing:border-box;}
html, body {font-family:'Inter',sans-serif;background:var(--bg);color:#333;margin:0;overflow-x:hidden;}
.login-wrap{min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:16px;}
.login-card{background:#fff;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);padding:36px;width:100%;max-width:380px;}
.login-card h4{color:var(--primary);font-weight:700;margin-bottom:10px;text-align: center}
.login-card .subtitle{color:#777;font-size:13.5px;margin-bottom:24px;}
.thi-header{display:flex;align-items:center;justify-content:space-between;background:var(--primary);color:#fff;padding:12px 22px;position:sticky;top:0;z-index:10;}
.thi-header .logo{font-weight:700;font-size:16px;}
.thi-header .logo small{display:block;font-weight:400;font-size:12px;opacity:.8;}
.thi-timer{background:rgba(255,255,255,.12);border-radius:8px;padding:8px 18px;font-size:18px;font-weight:700;display:flex;align-items:center;gap:8px;}
.thi-timer.warn{background:#c62828;}
/* Bố cục lại: sidebar trái (tiến trình) + cột nội dung (toolbar trên, câu hỏi giữa, timer/nộp bài dưới) */
.thi-body{display:flex;gap:0;align-items:stretch;height:100vh;height:100dvh;overflow:hidden;position:relative;}

.thi-sidebar-left{width:130px;flex-shrink:0;background:#fff;border-right:1px solid var(--border);padding:16px 12px;overflow-y:auto;overscroll-behavior: contain;}
.thi-sidebar-left h6{color:var(--primary);font-weight:700;margin-bottom:12px;font-size:16px;}
.thi-sidebar-left .progress-grid{grid-template-columns:repeat(1,1fr);}
.sidebar-left-header{display:none;}
.sidebar-close-btn{display:none;background:none;border:none;font-size:19px;color:#555;cursor:pointer;line-height:1;padding:4px;}

/* ==== THÊM MỚI: nút hamburger mở menu tiến trình trên di động ==== */
.btn-hamburger{display:none;background:transparent;border:none;color:#fff;font-size:19px;padding:6px 8px;cursor:pointer;flex-shrink:0;}
.sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040;}
.sidebar-backdrop.show{display:block;}

.thi-content-col{flex:1;display:flex;flex-direction:column;min-width:0;}
.thi-main{flex:1;overflow-y:auto;background:#fff;padding: 0;border:none;border-radius:0;max-height:none;min-height:0;overscroll-behavior: contain;}

.thi-bottombar{display:flex;align-items:center;justify-content:flex-end;gap:20px;background:#fff;border-top:1px solid var(--border);padding:10px 22px;}
.thi-bottombar .thi-timer-panel{margin-bottom:0;padding:8px 18px;background:#eef1ff;}
.thi-bottombar .btn-submit{width:auto;padding:10px 24px;}

/* Nút Thông tin (icon người) + popover bung ra khi bấm, mất khi click ra ngoài */
.info-btn-wrap{position:relative;}
.info-popover{display:none;position:absolute;top:100%;right:0;margin-top:8px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:14px 16px;width:240px;z-index:1100;text-align:left;}
.info-popover.show{display:block;}
.thi-side{width:300px;flex-shrink:0;position:sticky;}
.panel{background:#fff;border-radius:10px;border:1px solid var(--border);padding:18px;margin-bottom:16px;}
.panel h6{color:var(--primary);font-weight:700;margin-bottom:10px;}
.info-row{display:flex;justify-content:space-between;font-size:13.5px;padding:4px 0;border-bottom:1px dashed #eee;color: #000}
.btn-submit{width:100%;background:var(--danger);color:#fff;border:none;padding:12px;border-radius:8px;font-weight:700;font-size:15px;}
.progress-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;margin-top:10px;}
.progress-grid button{aspect-ratio:3/1;border-radius:6px;border:1px solid var(--border);background:#f5f5f5;font-size:12.5px;font-weight:600;cursor:pointer;}
.progress-grid button.answered{background:#c8e6c9;border-color:#81c784;color:#1b5e20;}
.progress-grid button.wrong{background:#ffcdd2;border-color:#ef9a9a;color:#b71c1c;}
.progress-grid button.wrong.flagged {
    background: linear-gradient(
        135deg,
        #ffcdd2 0%,
        #f2d9c9 35%,
        #f5e8c8 50%,
        #fff3cd 65%,
        #fff3cd 100%
    );
    border-color: #f57c00;
}
.progress-grid button.current{outline:2px solid var(--primary);outline-offset:1px;}
.thi-sidebar-left .progress-grid button{font-size:13px;padding:6px 10px;text-align:left}
.legend{display:flex;gap:14px;font-size:12px;color:#666;margin-top:10px;flex-wrap:wrap;}
.legend span{display:inline-flex;align-items:center;gap:5px;}
.legend i{width:12px;height:12px;border-radius:3px;display:inline-block;}
.q-count{color:var(--primary);font-weight:700;}
.q-vignette{line-height:1.6;margin-bottom:16px;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.q-vignette table{width:100%;border-collapse:collapse;margin:12px 0;font-size:13.5px;min-width:460px;}
.q-vignette th,.q-vignette td{border:1px solid var(--border);padding:8px 10px;}
.q-vignette th{background:#f8f9fa;}
.q-leadin{margin-bottom:14px;text-align: justify;}
.choice-option{display:flex;gap:10px;align-items:flex-start;border:1px solid var(--border);border-radius:8px;padding:12px 14px;margin-bottom:10px;cursor:pointer;transition:.15s;}
.choice-option:hover{border-color:var(--brand);background:#f7f8ff;}
.choice-option.selected{border-color:var(--brand);background:#e8eaf9;}
.choice-option.lt-correct{background:#e8f5e9;border-color:#a5d6a7;cursor:default;}
.choice-option.lt-wrong{background:#ffebee;border-color:#ef9a9a;cursor:default;}
.choice-option input{margin-top:6px;}
.ds-row{display:flex;justify-content:space-between;align-items:center;gap:14px;}
.ds-toggle{display:flex;gap:14px;flex-shrink:0;white-space:nowrap;}
.ds-toggle label{display:flex;align-items:center;gap:5px;font-weight:400;cursor:pointer;margin:0;}
.nav-btns{display:flex;justify-content:space-between;margin-top:22px;}
.nav-btns button{padding:10px 20px;border-radius:8px;border:1px solid var(--border);background:#fff;font-weight:600;cursor:pointer;}
.nav-btns button:disabled{opacity:.4;cursor:not-allowed;}
.result-summary{background:#fff;border-radius:10px;border:1px solid var(--border);padding:22px;margin-bottom:20px;display:flex;gap:24px;flex-wrap:wrap;align-items:center;}
.result-score{font-size:38px;font-weight:800;color:var(--primary);}
.result-card{background:#fff;border-radius:10px;border:1px solid var(--border);padding:20px;margin-bottom:16px;}
.choice-box{padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;}
.choice-box.correct{background:#e8f5e9;border-color:#a5d6a7;}
.choice-box.wrong-selected{background:#ffebee;border-color:#ef9a9a;}
.tag-correct{color:var(--success);font-weight:700;}
.tag-wrong{color:var(--danger);font-weight:700;}
.h3, h3 { font-size: 16px; color: var(--primary)}
.h6, h6 { color: var(--primary) !important}
.btn{align-items:center;gap:8px;padding:10px 10px;border-radius:6px;font-size:14px;font-weight:600;border:none;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-secondary{background:#f5f5f5;color:#555;border:1px solid #ddd;}
.btn-sm{padding:6px 14px;font-size:13px;}
.thi-timer-panel{color:#1a237e;border-radius:8px;padding:10px 18px;font-size:18px;font-weight:700;display:flex;align-items:center;justify-content:center;gap:8px;margin-bottom:14px;}
/* Thanh công cụ khi làm bài (giống giao diện mẫu) */
.thi-toolbar{display:flex;align-items:center;justify-content:space-between;background:var(--primary);color:#fff;padding:10px 20px;position:sticky;top:0;z-index:20;flex-wrap:wrap;row-gap:6px;}
.thi-toolbar .toolbar-left{display:flex;align-items:center;gap:16px;}
.thi-toolbar .item-info{background:rgba(255,255,255,.12);border-radius:8px;padding:8px 14px;font-size:13px;white-space:nowrap;}
.thi-toolbar .toolbar-nav{display:flex;align-items:center;gap:10px;}
.thi-toolbar .toolbar-nav button{background:transparent;border:none;color:#fff;font-size:13px;cursor:pointer;padding:6px 10px;}
.thi-toolbar .toolbar-nav button:disabled{opacity:.35;cursor:not-allowed;}
.toolbar-tools{display:flex;align-items:center;gap:4px;}
.toolbar-tools button{background:transparent;border:none;color:#fff;font-size:12px;display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer;padding:6px 14px;border-radius:6px;}
.toolbar-tools button i{font-size:18px;}
.toolbar-tools button:hover,.toolbar-tools button.active{background:rgba(255,255,255,.18);}

/* Bôi đen (highlight) trong đề bài */
.q-vignette mark.user-hl{background:#ffeb3b;color:inherit;padding:0;}

/* Panel nổi dùng chung cho Nháp & Máy tính */
.floating-panel{position:fixed;top:110px;right:40px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 8px 28px rgba(0,0,0,.18);z-index:1000;display:none;}
.floating-panel.show{display:block;}
.floating-panel .fp-header{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#eceff1;border-bottom:1px solid var(--border);border-radius:8px 8px 0 0;}
.floating-panel .fp-header b{font-size:13px;color:var(--primary);}
.floating-panel .fp-close{background:none;border:none;font-size:16px;cursor:pointer;color:#555;}

/* Panel Nháp (Notes) */
#notesPanel{width:340px;}
#notesPanel textarea{width:100%;height:220px;border:none;padding:12px;font-size:14px;resize:vertical;border-radius:0 0 8px 8px;}

/* Panel Máy tính (Calculator) */
#calcPanel{width:290px;}

/* MỚI: các class màu theo nhóm */
#calcPanel .calc-grid button.c-deg{background:#d1fae5;color:#065f46;}
#calcPanel .calc-grid button.c-deg:hover{background:#a7f3d0;}

#calcPanel .calc-grid button.c-mem{background:#fef3c7;color:#991b1b;}
#calcPanel .calc-grid button.c-mem:hover{background:#fde68a;}

#calcPanel .calc-grid button.c-num{background:#fff;border-color:#d1d5db;}
#calcPanel .calc-grid button.c-num:hover{background:#f3f4f6;}

#calcPanel .calc-grid button.c-op{background:#dbeafe;color:#1a237e;font-weight:700;}
#calcPanel .calc-grid button.c-op:hover{background:#bfdbfe;}

#calcPanel .calc-grid button.c-clr{background:#fee2e2;color:#b91c1c;font-weight:600;}
#calcPanel .calc-grid button.c-clr:hover{background:#fecaca;}
#calcPanel .calc-display{margin:10px;padding:8px 10px;text-align:right;background:#f5f5f5;border-radius:6px;min-height:54px;word-break:break-all;display:flex;flex-direction:column;justify-content:center;}
#calcPanel .calc-history{font-size:12px;color:#777;min-height:16px;height:18px}
#calcPanel .calc-display{position:relative;}
.calc-mem-indicator{position:absolute;top:6px;left:10px;font-size:11px;font-weight:700;color:var(--primary);background:#e8eaf9;border-radius:4px;padding:1px 6px;display:none;}
#calcPanel .calc-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:6px;padding:0 10px 12px;}
#calcPanel .calc-grid button.mem{font-size:11px;background:#eef1ff;color:var(--primary);font-weight:700;}
#calcPanel .calc-grid button{padding:7px 0;border:1px solid var(--border);background:#fafafa;border-radius:6px;font-size:12px;cursor:pointer;}
#calcPanel .calc-grid button:hover{background:#eef1ff;}
#calcPanel .calc-grid button.op{background:#e8eaf9;color:var(--primary);font-weight:700;}
#calcPanel .calc-grid button.eq{background:var(--primary);color:#fff;}
#calcPanel .calc-grid button.clr{background:#ffe0e0;color:var(--danger);}

/* Panel Bảng tham chiếu xét nghiệm (Lab Values) */
.thi-split{display:flex;height:100%;}
.split-question{flex:1;min-width:0;overflow-y:auto;transition:flex .15s;padding: 20px;}
.thi-split.lab-open .split-question{padding-right: 20px;flex:2;}
.thi-split.lab-open .q-layout{flex-direction:column;align-items:stretch;}
.thi-split.lab-open .q-image-col{width:100%;position:static;text-align: center}

#labPanel{display:none;flex-direction:column;flex:1;min-width:0;overflow-y:auto;border-left:1px solid var(--border);}
.thi-split.lab-open #labPanel{display:flex;}
#labPanel .lab-header{display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border);color:#1a237e;background:#eceff1}
#labPanel .lab-search{padding:10px 16px;border-bottom:1px solid var(--border);}
#labPanel .lab-search input{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px;}
#labPanel .lab-tabs{ display:flex; border-bottom:1px solid var(--border); overflow-x:auto; transform:rotateX(180deg); } 
#labPanel .lab-tabs button{ flex:1 1 auto; white-space:nowrap; padding:9px 14px; border:none; background:#fafafa; font-size:12px; font-weight:600; cursor:pointer; border-right:1px solid var(--border); transform:rotateX(180deg); }
#labPanel .lab-tabs button.active{background:#fff;color:var(--primary);border-bottom:2px solid var(--primary);}
#labPanel .lab-tabs button.has-match{box-shadow: inset 0 -3px 0 0 #ffe082;}
#labPanel .lab-body{flex:1;overflow-y:auto;overflow-x:auto;-webkit-overflow-scrolling:touch;padding:10px 16px;font-size:13px;}
#labPanel .lab-body table{min-width:300px;}
#labPanel .lab-body .lab-empty{color:#999;text-align:center;margin-top:40px;}
#labPanel .fp-close{background:none;border:none;font-size:16px;cursor:pointer;color:#555;}
#labPanel .lab-note{background:#fff8e1;border:1px solid #ffe082;border-radius:6px;padding:8px 12px;margin-bottom:12px;font-size:12.5px;color:#7a5c00;white-space:pre-wrap;}
#labPanel .lab-search{ flex-wrap:wrap; row-gap:8px; } 
#labPanel .lab-search input[type="text"]{ width:auto !important; min-width:0; flex:1 1 160px; } 
#labPanel .lab-search label{ flex:0 0 auto; white-space:nowrap; } 
#labPanel .lab-search input[type="checkbox"]{ width:auto !important; height:auto !important; padding:0 !important; border:none !important; border-radius:0 !important; margin:0; flex:0 0 auto; }
/* Đếm số lượng chỉ số cạnh tên bảng / tên nhóm + đóng-mở nhóm nhỏ */
#labTabs button .lab-count{font-weight:400;opacity:.75;margin-left:3px;}
.lab-group-header{cursor:pointer;user-select:none;display:flex;align-items:center;gap:4px;}
.lab-group-header:hover{opacity:.85;}
.lab-group-header .lab-group-toggle{font-size:11px;transition:transform .15s;flex-shrink:0;}
.lab-group-header.collapsed .lab-group-toggle{transform:rotate(-90deg);}
.lab-group-header .lab-group-count{font-weight:400;opacity:.7;font-size:12px;margin-left:2px;}
tr.lab-group-row.hidden{display:none;}
/* Bố cục câu hỏi + ảnh bên phải */
.q-layout{display:flex;gap:24px;align-items:flex-start;}
.q-text-col{flex:1;min-width:0;}
.q-image-col{width:360px;flex-shrink:0;position:sticky;top:12px;}
.q-image-col.wide-img{width:45%;}
.q-image-col img{max-width:100%;border-radius:8px;cursor:zoom-in;display:block;}
.auscul-side-col{display:flex;gap:14px;flex-shrink:0;flex-wrap:wrap;align-items:flex-start;position:sticky;top:12px;margin-bottom:10px}

.auscul-image-box{width:190px;flex-shrink:0;border:1px solid var(--border);border-radius:10px;padding:8px;background:#fafbfc;}
.auscul-img-wrap{position:relative;display:block;width:100%;}
.auscul-img-wrap img{width:100%;border-radius:8px;display:block;}
.auscul-markers{position:absolute;top:0;left:0;width:100%;height:100%;}
.auscul-marker-dot{position:absolute;width:18px;height:18px;border-radius:50%;background:rgba(40,53,147,.85);border:2px solid #fff;
  transform:translate(-50%,-50%);cursor:pointer;box-shadow:0 1px 5px rgba(0,0,0,.4);transition:.15s;}
.auscul-marker-dot:hover{background:#1a237e;transform:translate(-50%,-50%) scale(1.15);}
.auscul-marker-dot.active{background:#f57c00;animation:auscul-pulse 1.2s infinite;}
@keyframes auscul-pulse{0%{box-shadow:0 0 0 0 rgba(245,124,0,.55);}70%{box-shadow:0 0 0 9px rgba(245,124,0,0);}100%{box-shadow:0 0 0 0 rgba(245,124,0,0);}}

.auscul-right-col{display:flex;flex-direction:column;gap:8px;width:300px;flex-shrink:0;}
.auscul-timer{font-weight:700;font-variant-numeric:tabular-nums;background:#1a237e;color:#fff;border-radius:8px;padding:8px 14px;font-size:14px;display:flex;align-items:center;justify-content:center;gap:8px;}
.auscul-timer button{background:none;border:none;color:#fff;cursor:pointer;padding:0;font-size:13px;line-height:1;}
.auscul-function-box{width:100%;flex-shrink:0;border:1px solid var(--border);border-radius:10px;background:#fff;}
.auscul-func-header{display:flex;flex-direction:column;gap:6px;padding:10px 14px;background:#eef1ff;border-bottom:1px solid var(--border);border-radius:10px 10px 0 0;}
.auscul-point-name{font-weight:700;color:var(--primary);font-size:13px;}
.auscul-func-body{padding:12px 14px;}
.auscul-player{display:flex;flex-wrap:wrap;align-items:center;gap:6px;background:#f5f6fb;border-radius:8px;padding:8px;}
.auscul-vol-wrap{position:relative;flex:0 0 auto;}
.auscul-vol-btn{width:30px;height:30px;border-radius:50%;background:#e8eaf9;color:var(--primary);border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;}
.auscul-vol-btn:hover{background:#dde0f5;}
.auscul-vol-popup{display:none;position:absolute;bottom:100%;left:50%;transform:translateX(-50%);margin-bottom:8px;background:#fff;border:1px solid var(--border);border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.2);padding:8px 10px;z-index:60;}
.auscul-vol-popup.show{display:block;}
.auscul-vol-popup input[type=range]{width:90px;vertical-align:middle;}
.auscul-mat-select{border:1px solid var(--border);border-radius:6px;font-size:11.5px;padding:4px 5px;flex:0 0 auto;max-width:90px;}
.auscul-play-btn{flex:0 0 auto;width:30px;height:30px;border-radius:50%;background:var(--brand);color:#fff;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;}
.auscul-play-btn:hover{background:var(--primary);}
.auscul-seek{flex:1 1 50%;order:3;}
.auscul-time{flex:0 0 auto;font-size:10.5px;color:#666;font-variant-numeric:tabular-nums;white-space:nowrap;order:4;margin-left:auto;}

@media (max-width:900px){
  .auscul-side-col{width:100%;flex-direction:column;position:static;}
  .auscul-image-box,.auscul-right-col{width:100%;}
}
.img-caption{font-size:12.5px;color:#777;font-style:italic;margin-top:6px;text-align:center;}
.img-tools{display:flex;justify-content:center;gap:4px;margin-top:8px;flex-wrap:wrap;}
.img-tools button{border:1px solid var(--border);background:#fff;border-radius:6px;padding:5px 9px;font-size:12px;cursor:pointer;}
.img-tools button:hover{background:#f0f2ff;}
.img-slider-wrap{position:relative;display:inline-flex;}
.img-slider-popup{
  display:none;position:absolute;top:100%;left:50%;transform:translateX(-50%);
  margin-top:8px;background:#fff;border:1px solid var(--border);border-radius:8px;
  box-shadow:0 6px 18px rgba(0,0,0,.2);padding:10px 12px;z-index:50;white-space:nowrap;
}
.img-slider-popup.show{display:block;}
.img-slider-popup input[type=range]{width:140px;vertical-align:middle;}
.img-tools button.active{background:#e8eaf9;border-color:var(--brand);color:var(--primary);}
.img-lightbox .lb-toolbar button.active{background:rgba(255,255,255,.35);}
@media (max-width:900px){ .q-layout{flex-direction:column;} .q-image-col{width:100%;position:static;} }

/* Lightbox phóng to ảnh toàn màn hình */
.q-image-col img {
  display: block;
  margin: 0 auto;
}
.img-lightbox{ display:none; position:fixed; inset:0; background:rgba(0,0,0,.92); z-index:2000; flex-direction:column; } 
.img-lightbox.show{display:flex;} 
.img-lightbox .lb-toolbar{ order:1; flex-shrink:0; display:flex; gap:8px; flex-wrap:wrap; justify-content:center; padding:12px 14px; background:rgba(0,0,0,.55); position:relative; z-index:5; } 
.img-lightbox .lb-image-wrap{ order:2; flex:1; width:100%; min-height:0; overflow:hidden; display:flex; align-items:center; justify-content:center; cursor:grab; } 
.img-lightbox .lb-image-wrap.dragging{cursor:grabbing;} .img-lightbox img{ max-width:100%; max-height:100%; object-fit:contain; transition:transform .1s; user-select:none; -webkit-user-drag:none; }
.img-lightbox .lb-toolbar button{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:6px;padding:8px 12px;font-size:12.5px;cursor:pointer;}
.img-lightbox .lb-toolbar button:hover{background:rgba(255,255,255,.25);}
.img-lightbox .lb-close{position:absolute;top:20px;right:28px;background:none;border:none;color:#fff;font-size:28px;cursor:pointer;z-index:10;}

.progress-grid button{font-size:10px;line-height:1.3;}
.progress-grid button.flagged{background:#fff3cd;border-color:#ffca28;position:relative;}
.progress-grid button.flagged::after{content:"⚑";position:absolute;top:-6px;right:-2px;color:#f57c00;font-size:11px;background:#fff;border-radius:50%;line-height:1;padding:1px 2px;}
.progress-grid button.answered.flagged {
    background: linear-gradient(
        135deg,
        #c8e6c9 0%,
        #dce9c9 35%,
        #e8e8c8 50%,
        #fff3cd 65%,
        #fff3cd 100%
    );
    border-color: #f57c00;
}
.toolbar-tools button.flag-active{background:#ffc107;color:#1a237e;}
.toolbar-tools button.flag-active:hover{background:#ffca28;}
.toolbar-nav button.flag-active{background:#ffc107;color:#1a237e;}
.toolbar-nav button.flag-active:hover{background:#ffca28;}
table thead {text-align:center}
p {margin-bottom:5px;text-align: justify;}

/* ======================================================================
   ==== THÊM MỚI: RESPONSIVE CHO ĐIỆN THOẠI (mobile) ====
   ====================================================================== */

/* ---- Nút hamburger mở "Tiến trình" dạng menu trượt trên di động ---- */
@media (max-width:768px){
  .btn-hamburger{display:inline-flex;align-items:center;}

  /* Sidebar tiến trình -> chuyển thành drawer trượt từ trái, ẩn mặc định */
  .thi-sidebar-left{
    position:fixed;top:0;left:0;height:100vh;height:100dvh;width:80%;max-width:300px;
    z-index:1050;transform:translateX(-100%);transition:transform .25s ease;
    box-shadow:2px 0 18px rgba(0,0,0,.28);
  }
  .thi-sidebar-left.open{transform:translateX(0);}
  .thi-sidebar-left .progress-grid{grid-template-columns:repeat(3,1fr);}
  .sidebar-left-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
  .sidebar-left-header h6{margin-bottom:0;}
  .sidebar-close-btn{display:inline-flex;align-items:center;justify-content:center;}

  /* Toolbar: xuống 2 hàng, thu gọn chữ thành icon */
  .thi-toolbar{padding:8px 10px;gap:6px;}
  .thi-toolbar .toolbar-left{flex:1 1 100%;justify-content:space-between;gap:6px;}
  .thi-toolbar .item-info{padding:6px 10px;font-size:12px;}
  .thi-toolbar .toolbar-nav{gap:2px;}
  .thi-toolbar .toolbar-nav button{padding:6px 7px;font-size:12px;}
  .thi-toolbar .toolbar-nav button .nav-text{display:none;}
  .toolbar-tools{flex:1 1 100%;justify-content:center;gap:2px;}
  .toolbar-tools button{padding:6px 12px;}
  .toolbar-tools button span{display:none;}
  .toolbar-tools button i{font-size:17px;}
  .info-popover{width:210px;right:-8px;}

  /* Thanh dưới cùng: xếp dọc, nút nộp bài full width */
  .thi-bottombar{flex-direction:column;align-items:stretch;gap:8px;padding:10px 14px;}
  .thi-bottombar .thi-timer-panel{width:100%;margin-bottom:0;}
  .thi-bottombar .btn-submit{width:100%;}

  .split-question{padding:14px;}

  /* Bảng xét nghiệm: khi mở thì chiếm toàn màn hình thay vì chia đôi */
  .thi-split.lab-open .split-question{display:none;}
  .thi-split.lab-open #labPanel{width:100%;border-left:none;}

  /* Panel Nháp / Máy tính: chuyển thành bottom-sheet full-width */
  .floating-panel{
    left:8px !important;right:8px !important;top:auto !important;bottom:70px !important;
    width:auto !important;max-width:none;max-height:58vh;
  }
  #notesPanel textarea{height:130px;}
  #calcPanel .calc-grid button{padding:9px 0;font-size:12.5px;}

  .login-card{padding:26px 20px;}

  .img-lightbox .lb-toolbar button{padding:6px 9px;font-size:11.5px;}
}

@media (max-width:420px){
  .thi-toolbar .item-info{order:1;}
  .toolbar-tools button{padding:6px 9px;}
}
</style>
</head>
<body>

<?php if ($showLogin): ?>
<!-- ================= ĐĂNG NHẬP ================= -->
<div class="login-wrap">
  <div class="login-card">
    <h4><i class="bi bi-capsule-pill"></i> ÔN TẬP Y DƯỢC</h4>
    <?php if ($loginErr): ?><div class="alert alert-danger py-2 small"><?= e($loginErr) ?></div><?php endif; ?>
    <form method="post" action="?action=login&mode=<?= e($requestMode) ?>">
  	  <input type="hidden" name="mode" value="<?= e($requestMode) ?>">
      <label class="form-label small fw-bold">Tên đăng nhập</label>
      <input type="text" name="sbd" class="form-control mb-3" required autofocus placeholder="Nhập tên đăng nhập...">
      <label class="form-label small fw-bold">Mật khẩu</label>
      <input type="text" name="password" class="form-control mb-4" required placeholder="Nhập mật khẩu...">
      <button type="submit" class="btn btn-primary btn-sm" style="width:100%;text-align: center">Đăng nhập</button>
    </form>
  </div>
</div>

<?php elseif ($showExam): ?>
<!-- ================= LÀM BÀI ================= -->
<div class="thi-body">
  <!-- THÊM MỚI: lớp phủ mờ khi mở drawer "Tiến trình" trên di động -->
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="thi-sidebar-left" id="sidebarLeft">
    <div class="sidebar-left-header">
      <h6 style="margin:0">Tiến trình</h6>
      <button type="button" class="sidebar-close-btn" id="sidebarCloseBtn" aria-label="Đóng"><i class="bi bi-x-lg"></i></button>
    </div>
    <h6 style="text-align: center" class="d-none d-md-block">Tiến trình</h6>
    <div class="progress-grid" id="progressGrid"></div>
  </div>

  <div class="thi-content-col">
    <div class="thi-toolbar">
      <div class="toolbar-left">
        <!-- THÊM MỚI: nút mở menu "Tiến trình" (chỉ hiện trên di động) -->
        <button class="btn-hamburger" id="btnDrawer" type="button" aria-label="Danh sách câu hỏi"><i class="bi bi-list"></i></button>
        <div class="item-info" id="qCount"></div>
        <div class="toolbar-nav">
          <button id="btnPrev" type="button"><i class="bi bi-chevron-left"></i>Câu trước</button>
          <button id="btnNext" type="button">Câu sau <i class="bi bi-chevron-right"></i></button>
          <button id="btnFlag" type="button"><i class="bi bi-flag"></i><span class="nav-text"> Đánh dấu</span></button>
        </div>
      </div>
      <div class="toolbar-tools">
        <button id="btnLab" type="button"><i class="bi bi-clipboard2-pulse"></i><span>Xét nghiệm</span></button>
        <button id="btnNotes" type="button"><i class="bi bi-journal-text"></i><span>Giấy nháp</span></button>
        <button id="btnCalc" type="button"><i class="bi bi-calculator"></i><span>Máy tính</span></button>
        <div class="info-btn-wrap">
          <button id="btnInfo" type="button"><i class="bi bi-person-circle"></i><span>Thông tin</span></button>
          <div class="info-popover" id="infoPopover">
            <div class="info-row"><span>Tên đề</span><b><?= e($phien['ten_matran']) ?></b></div>
            <div class="info-row"><span>Số báo danh</span><b><?= e($phien['sbd']) ?></b></div>
            <div class="info-row"><span>Mã đề</span><b><?= e($phien['ma_de']) ?></b></div>
            <div class="info-row"><span>Tổng số câu</span><b><?= (int)$phien['total_cau'] ?></b></div>
            <div class="info-row"><span>Trạng thái</span><span class="text-warning fw-bold">Đang làm bài</span></div>
          </div>
        </div>
      </div>
    </div>

    <div class="thi-main">
    <div class="thi-split" id="thiSplit">
        <div class="split-question" id="qBody"></div>
        <div id="labPanel">
        <div class="lab-header"><b><i class="bi bi-clipboard2-pulse"></i> Bảng tham chiếu xét nghiệm</b><button class="fp-close" id="labClose">&times;</button></div>
        <!-- <-- SỬA: thêm checkbox Tham chiếu SI -->
        <div class="lab-search" style="display:flex;align-items:center;gap:10px;">
          <input type="text" id="labSearch" placeholder="Tìm kiếm chỉ số..." style="flex:1">
          <div style="align-items:center;gap:5px;font-size:12.5px;white-space:nowrap;cursor:pointer;margin:0;display:flex!important">
            <input type="checkbox" id="labSIToggle"> Tham chiếu SI
          </div>
        </div>
        <div class="lab-tabs" id="labTabs">
        <?php if (empty($labTablesJs)): ?>
            <div class="lab-empty" style="padding:10px 16px">Chưa có bảng xét nghiệm nào.</div>
        <?php else: foreach ($labTablesJs as $i => $lt): ?>
            <button type="button" class="<?= $i===0?'active':'' ?>" data-tab-id="<?= (int)$lt['id'] ?>" onclick="switchLabTab(this)"><?= e($lt['ten_bang']) ?></button>
        <?php endforeach; endif; ?>
        </div>
        <div class="lab-body" id="labBody">
            <div class="lab-empty">Dữ liệu bảng tham chiếu sẽ được cập nhật sau.</div>
        </div>
        </div>
    </div>
    </div>

    <div class="thi-bottombar">
      <div class="thi-timer-panel" id="timerBox"><i class="bi bi-clock-fill"></i> <span id="timerText">--:--:--</span></div>
      <?php if ($isLuyenTap): ?>
        <button type="button" class="btn-submit btn-sm" onclick="window.location.href='?action=logout'"> 
            <i class="bi bi-shuffle"></i> KẾT THÚC 
        </button>
      <?php else: ?>
        <button id="btnSubmit" class="btn-submit btn-sm"><i class="bi bi-send-check"></i> NỘP BÀI</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- các panel Nháp / Máy tính / Bảng tham chiếu XN giữ nguyên như lượt trước, đặt ngay sau đây -->
<!-- Nháp -->
<div class="floating-panel" id="notesPanel">
  <div class="fp-header"><b><i class="bi bi-journal-text"></i> Giấy nháp</b><button class="fp-close" id="notesClose">&times;</button></div>
  <textarea id="notesText" placeholder="Ghi chú nháp của bạn..."></textarea>
</div>

<!-- Máy tính -->
<div class="floating-panel" id="calcPanel">
  <div class="fp-header"><b><i class="bi bi-calculator"></i> Máy tính</b><button class="fp-close" id="calcClose">&times;</button></div>
  <div class="calc-display">
    <div class="calc-history" id="calcHistory"></div>
    <div id="calcDisplay">0</div>
  </div>
  <div class="calc-mem-indicator" id="calcMemIndicator">M</div>
  <div class="calc-grid">
    <!-- Hàng 1: chế độ + bộ nhớ -->
    <button class="c-deg" id="calcDegBtn" data-calc="deg">DEG</button>
    <button class="c-mem" data-calc="mc">MC</button>
    <button class="c-mem" data-calc="mr">MR</button>
    <button class="c-mem" data-calc="m+">M+</button>
    <button class="c-mem" data-calc="m-">M-</button>

    <!-- Hàng 2: hàm lượng giác -->
    <button data-calc="sin">sin</button>
    <button data-calc="cos">cos</button>
    <button data-calc="tan">tan</button>
    <button data-calc="log">log</button>
    <button data-calc="ln">ln</button>

    <!-- Hàng 3: hàm lượng giác ngược + x² -->
    <button data-calc="asin">sin⁻¹</button>
    <button data-calc="acos">cos⁻¹</button>
    <button data-calc="atan">tan⁻¹</button>
    <button data-calc="sqrt">√</button>
    <button data-calc="sq">x²</button>

    <!-- Hàng 4: hằng số + ngoặc -->
    <button data-calc="(">(</button>
    <button data-calc=")">)</button>
    <button data-calc="pi">π</button>
    <button data-calc="econst">e</button>
    <button data-calc="^">^</button>

    <!-- Hàng 5: số + chia + xoá hết -->
    <button class="c-num" data-calc="7">7</button>
    <button class="c-num" data-calc="8">8</button>
    <button class="c-num" data-calc="9">9</button>
    <button class="c-op" data-calc="/">÷</button>
    <button class="c-clr" data-calc="C">AC</button>

    <!-- Hàng 6: số + nhân + xoá 1 ký tự -->
    <button class="c-num" data-calc="4">4</button>
    <button class="c-num" data-calc="5">5</button>
    <button class="c-num" data-calc="6">6</button>
    <button class="c-op" data-calc="*">×</button>
    <button class="c-clr" data-calc="back">⌫</button>

    <!-- Hàng 7: số + trừ + Ans -->
    <button class="c-num" data-calc="1">1</button>
    <button class="c-num" data-calc="2">2</button>
    <button class="c-num" data-calc="3">3</button>
    <button class="c-op" data-calc="-">-</button>
    <button data-calc="ans">Ans</button>

    <!-- Hàng 8: 0 + . + cộng + bằng -->
    <button class="c-num" data-calc="0" style="grid-column:span 2">0</button>
    <button class="c-num" data-calc=".">.</button>
    <button class="c-op" data-calc="+">+</button>
    <button class="eq" data-calc="=">=</button>
  </div>
</div>

<div class="img-lightbox" id="imgLightbox">
  <button class="lb-close" id="lbClose">&times;</button>
  <div class="lb-image-wrap">
    <img id="lbImage" src="" alt="">
  </div>
  <div class="lb-toolbar">
    <button id="lbZoomIn"><i class="bi bi-zoom-in"></i> Phóng to</button>
    <button id="lbZoomOut"><i class="bi bi-zoom-out"></i> Thu nhỏ</button>
    <div class="img-slider-wrap">
      <button type="button" data-slider="brightness"><i class="bi bi-brightness-high"></i> Độ sáng</button>
      <div class="img-slider-popup"><input type="range" min="30" max="200" value="100" data-slider-input="brightness"></div>
    </div>
    <div class="img-slider-wrap">
      <button type="button" data-slider="contrast"><i class="bi bi-circle-half"></i> Tương phản</button>
      <div class="img-slider-popup"><input type="range" min="30" max="200" value="100" data-slider-input="contrast"></div>
    </div>
    <button type="button" data-adj="invert"><i class="bi bi-yin-yang"></i> Đảo màu</button>
    <button data-adj="reset"><i class="bi bi-arrow-counterclockwise"></i> Đặt lại</button>
  </div>
</div>
<form id="submitForm" method="post" action="?action=submit" style="display:none">
  <input type="hidden" name="sid" value="<?= (int)$phien['id'] ?>">
</form>

<script>
const SID = <?= (int)$phien['id'] ?>;
const IS_LUYEN_TAP = <?= $isLuyenTap ? 'true' : 'false' ?>; // <-- THÊM MỚI
const EXAM_QUESTIONS = <?= json_encode($examQuestionsJs, JSON_UNESCAPED_UNICODE) ?>;
let answers = <?= json_encode($savedAnswers, JSON_UNESCAPED_UNICODE) ?>;
const FLAG_KEY = 'flag_' + SID;
let flagged = {};
try { flagged = JSON.parse(sessionStorage.getItem(FLAG_KEY) || '{}'); } catch(e){ flagged = {}; }

function toggleFlag(){
  const key = String(curIdx);
  if (flagged[key]) delete flagged[key]; else flagged[key] = true;
  sessionStorage.setItem(FLAG_KEY, JSON.stringify(flagged));
  render();
}
const END_TIME_MS = <?= strtotime($phien['end_time']) * 1000 ?>;
function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fixNewlines(str) {
  const s = (str || '').replace(/\\n/g, '\n').replace(/\r\n/g, '\n');
  const lines = s.split('\n');
  const isTableLine = l => l.includes('|') && l.trim() !== '';
  let out = '';
  for (let i = 0; i < lines.length; i++) {
    out += lines[i];
    if (i < lines.length - 1) {
      out += (isTableLine(lines[i]) && isTableLine(lines[i+1])) ? '\n' : '\n\n';
    }
  }
  return out;
}
function chuanHoaGroupCase(q) {
  if (!q || q.kind !== 'group' || !q.parts || q.parts.length < 2) return q;
  if (q._chuanHoaDone) return q;
  q._chuanHoaDone = true;

  const getParas = (text) => {
    const raw = (text || '').replace(/\\n/g, '\n').replace(/\r\n/g, '\n');
    const rawLines = raw.split('\n');
    const isTableLine = l => l.includes('|') && l.trim() !== '';
    const paras = [];
    let i = 0;
    while (i < rawLines.length) {
      const line = rawLines[i];
      if (line.trim() === '') { i++; continue; }
      if (isTableLine(line)) {
        const block = [line];
        let j = i + 1;
        while (j < rawLines.length && isTableLine(rawLines[j])) { block.push(rawLines[j]); j++; }
        paras.push(block.join('\n'));
        i = j;
      } else {
        paras.push(line.trim());
        i++;
      }
    }
    return paras;
  };

  const p0Paras = getParas(q.parts[0].lead_in);
  const p1Paras = getParas(q.parts[1].lead_in);

  if (p0Paras.length === 0) return q;

  // --- TRƯỜNG HỢP 1: Các ý lặp lại đoạn văn giống nhau (Ví dụ 8.1 & 8.2) ---
  let commonParas = [];
  for (let k = 0; k < p0Paras.length - 1 && k < p1Paras.length - 1; k++) {
    if (p0Paras[k] === p1Paras[k]) {
      commonParas.push(p0Paras[k]);
    } else {
      break;
    }
  }

  if (commonParas.length > 0) {
    if (!q.group_stem) q.group_stem = commonParas.join('\n\n');
    q.parts.forEach(p => {
      const paras = getParas(p.lead_in);
      let matchCount = 0;
      for (let k = 0; k < commonParas.length; k++) {
        if (paras[k] === commonParas[k]) matchCount++;
        else break;
      }
      if (matchCount > 0) p.lead_in = paras.slice(matchCount).join('\n\n');
    });
    return q;
  }

  // --- TRƯỜNG HỢP 2: Ý 2.1 chứa bệnh án ban đầu + câu hỏi, ý 2.2 là diễn tiến tiếp theo ---
  if (p0Paras.length >= 2) {
    // Đoạn đầu của 2.1 là bệnh án chung đưa lên khung trên
    if (!q.group_stem) {
      q.group_stem = p0Paras.slice(0, -1).join('\n\n');
    }
    // Đoạn cuối cùng của 2.1 là câu hỏi riêng của 2.1
    q.parts[0].lead_in = p0Paras[p0Paras.length - 1];
    // Ý 2.2 giữ nguyên toàn bộ diễn tiến + câu hỏi của nó
  }

  return q;
}
function hideReferenceRangeColumns(container){
  container.querySelectorAll('table').forEach(table => {
    const headerRow = table.querySelector('tr');
    if (!headerRow) return;
    Array.from(headerRow.children).forEach((cell, colIndex) => {
      if (cell.textContent.trim().toLowerCase().includes('khoảng tham chiếu')) {
        table.querySelectorAll('tr').forEach(row => {
          if (row.children[colIndex]) row.children[colIndex].style.display = 'none';
        });
      }
    });
  });
}
function formatChem(s){
  let t = esc(s);
  t = t.replace(/\^(\+|-|\d+)/g, '<sup>$1</sup>');
  t = t.replace(/_(\d+)/g, '<sub>$1</sub>');
  return t;
}
function chuanHoaTen(s){ return (s||'').toString().trim().toLowerCase(); }
function isEmptyVal(v){ return v === undefined || v === null || String(v).trim() === ''; }

// Áp override (đơn vị/tham chiếu riêng theo câu, KHÔNG đụng SI) vào 1 entry, trả về bản clone
function apDungOverrideChoEntry(entry, key, overrideMap){
  const ov = overrideMap[key];
  if (!ov) return { entry, hasResult:false };
  const clone = JSON.parse(JSON.stringify(entry));
  if (!isEmptyVal(ov.don_vi))     clone.units = [ov.don_vi];
  if (!isEmptyVal(ov.tham_chieu)) clone.refs  = [ov.tham_chieu];
  return { entry: clone, hasResult: !isEmptyVal(ov.gia_tri) };
}

// Kiểm tra tại vị trí i, giá trị tham chiếu hiệu lực (có tính fallback SI->thường) có rỗng không
function coGiaTriThamChieuTaiIndex(entry, i){
  const refs   = (entry.refs && entry.refs.length) ? entry.refs : [''];
  const siRefs = entry.si_refs || [];
  const val = siMode ? siValOrFallback(siRefs, refs, i) : refs[i];
  return !isEmptyVal(val);
}

function entryCoNenHienThi(entry, hasResult){
  if (hasResult) return true;
  const refs = (entry.refs && entry.refs.length) ? entry.refs : [''];
  for (let i = 0; i < refs.length; i++){
    if (coGiaTriThamChieuTaiIndex(entry, i)) return true;
  }
  return false;
}

// Trạng thái đóng/mở của từng nhóm nhỏ trong bảng tham chiếu (mặc định: mở)
let labGroupCollapsed = {};
function toggleLabGroup(key){
  labGroupCollapsed[key] = !labGroupCollapsed[key];
  const collapsed = labGroupCollapsed[key];
  document.querySelectorAll('tr.lab-group-row[data-grp="'+key+'"]').forEach(r=>{
    r.classList.toggle('hidden', collapsed);
  });
  const header = document.querySelector('.lab-group-header[data-grp="'+key+'"]');
  if (header) header.classList.toggle('collapsed', collapsed);
}

// Đếm số chỉ số sẽ hiển thị trong 1 nhóm nhỏ (áp dụng override/kết quả riêng nếu có)
function demSoChiSoNhom(entries, overrideMap){
  let count = 0;
  (entries||[]).forEach(en=>{
    const key = chuanHoaTen((en.values && en.values[0]) || '');
    const { entry: enOv, hasResult } = apDungOverrideChoEntry(en, key, overrideMap);
    if (entryCoNenHienThi(enOv, hasResult)) count++;
  });
  return count;
}

// Đếm tổng số chỉ số sẽ hiển thị trong toàn bộ 1 bảng (KHÔNG bị ảnh hưởng bởi ô tìm kiếm)
function demTongSoChiSoBang(groups, overrideMap){
  let total = 0;
  (groups||[]).forEach(g=>{
    if (g.is_single) {
      const key = chuanHoaTen(g.ten_nhom);
      const { entry: en0, hasResult } = apDungOverrideChoEntry(g.entries[0] || {}, key, overrideMap);
      if (entryCoNenHienThi(en0, hasResult)) total++;
    } else {
      total += demSoChiSoNhom(g.entries, overrideMap);
    }
  });
  return total;
}

// Gộp dữ liệu bảng gốc + chỉ số riêng của câu (extras) — dùng chung cho mọi nơi cần dataVoiExtra
function layDataVoiExtraChoBang(tableId){
  const t = LAB_TABLES.find(x => x.id === tableId);
  let data = (t && t.data) ? t.data : [];
  const extras = currentMergeExtras[tableId] || [];
  if (extras.length) {
    data = data.concat(extras.map(ex => ({
      is_single:false, ten_nhom: ex.nhom||'',
      entries:[{values:[ex.chi_so], refs:[ex.tham_chieu||''], units:[ex.don_vi||''], si_refs:[], si_units:[]}]
    })));
  }
  return data;
}

// Gắn key nhóm + số lượng cho từng "khối" nhóm liên tiếp trong bảng xét nghiệm riêng của câu (customXnInstances)
function ganNhomKeyChoKetQua(ketQua, prefix){
  const rowsKeep = (ketQua||[]).filter(kq => !isEmptyVal(kq.gia_tri) || !isEmptyVal(kq.tham_chieu));
  let curNhom = null, curKey = null, blockIdx = 0;
  const grpCounts = {};
  rowsKeep.forEach(kq => {
    if (kq.nhom) {
      if (kq.nhom !== curNhom) { curNhom = kq.nhom; curKey = prefix + '_g' + (blockIdx++); }
      kq.__grpKey = curKey;
      grpCounts[curKey] = (grpCounts[curKey]||0) + 1;
    } else { curNhom = null; delete kq.__grpKey; }
  });
  rowsKeep.forEach(kq => { if (kq.__grpKey) kq.__grpCount = grpCounts[kq.__grpKey]; });
}

let curIdx = 0;
let curPartIdx = 0;          // ý đang xem trong case study
let lastGroupQuestionIdx = -1;
const N = EXAM_QUESTIONS.length;
let vignetteHighlights = {}; // lưu HTML đã bôi đen theo từng câu (curIdx)

function renderProgress(){
  const grid = document.getElementById('progressGrid');
  grid.innerHTML = '';
  for (let i=0;i<N;i++){
    const b = document.createElement('button');
    b.type = 'button';
    b.innerHTML = 'Câu ' + (i+1);
    const q = EXAM_QUESTIONS[i];
    const isAnswered = q.kind === 'group'
      ? (q.parts && q.parts.every((p, j) => daTraLoiDayDu(p.loai_cau || 'mot_dap_an', p.choices, answers[i + '_' + j])))
      : daTraLoiDayDu(q.loai_cau || 'mot_dap_an', q.choices, answers[String(i)]);

    if (isAnswered) {
      if (IS_LUYEN_TAP) {
        const isCorrect = q.kind === 'group'
          ? (q.parts && q.parts.every((p, j) => dapAnDung(p.loai_cau || 'mot_dap_an', p.choices, answers[i + '_' + j])))
          : dapAnDung(q.loai_cau || 'mot_dap_an', q.choices, answers[String(i)]);
        b.classList.add(isCorrect ? 'answered' : 'wrong');
      } else {
        b.classList.add('answered');
      }
    }

    if (flagged[String(i)]) b.classList.add('flagged');
    if (i === curIdx) b.classList.add('current');
    b.onclick = () => { curIdx = i; render(); closeDrawer(); };
    grid.appendChild(b);
  }
}

let imgAdjust = { brightness:100, contrast:100, invert:false };

function applyImgFilter(){
  const f = 'brightness(' + imgAdjust.brightness + '%) contrast(' + imgAdjust.contrast + '%)'
          + (imgAdjust.invert ? ' invert(1)' : '');
  document.querySelectorAll('.q-zoomable-img').forEach(img => img.style.filter = f);
  lbImage.style.filter = f;
}

function buildImgTools(){
  return '<div class="img-tools">'
    + '<div class="img-slider-wrap">'
    +   '<button type="button" data-slider="brightness" title="Độ sáng"><i class="bi bi-brightness-high"></i></button>'
    +   '<div class="img-slider-popup"><input type="range" min="30" max="200" value="100" data-slider-input="brightness"></div>'
    + '</div>'
    + '<div class="img-slider-wrap">'
    +   '<button type="button" data-slider="contrast" title="Tương phản"><i class="bi bi-circle-half"></i></button>'
    +   '<div class="img-slider-popup"><input type="range" min="30" max="200" value="100" data-slider-input="contrast"></div>'
    + '</div>'
    + '<button type="button" data-adj="invert" title="Đảo màu"><i class="bi bi-yin-yang"></i></button>'
    + '<button data-adj="reset" title="Đặt lại ảnh"><i class="bi bi-arrow-counterclockwise"></i></button>'
    + '</div>';
}

function syncImgToolsUI(scope){
  scope.querySelectorAll('[data-slider-input]').forEach(inp=>{
    inp.value = imgAdjust[inp.dataset.sliderInput];
  });
  scope.querySelectorAll('[data-adj="invert"]').forEach(b=>{
    b.classList.toggle('active', imgAdjust.invert);
  });
}

function bindImgTools(scope){
  // Bấm nút -> mở/đóng popup thanh trượt
  scope.querySelectorAll('[data-slider]').forEach(btn=>{
    btn.onclick = (e) => {
      e.stopPropagation();
      const popup = btn.parentElement.querySelector('.img-slider-popup');
      const isOpen = popup.classList.contains('show');
      scope.querySelectorAll('.img-slider-popup.show').forEach(p => p.classList.remove('show'));
      if (!isOpen) popup.classList.add('show');
    };
  });
  // Kéo thanh trượt -> cập nhật ảnh
  scope.querySelectorAll('[data-slider-input]').forEach(input=>{
    const key = input.dataset.sliderInput;
    input.value = imgAdjust[key];
    input.oninput = () => {
      imgAdjust[key] = parseInt(input.value, 10);
      applyImgFilter();
    };
  });
  // Đảo màu / Đặt lại
  scope.querySelectorAll('[data-adj]').forEach(btn=>{
    btn.onclick = () => {
      if (btn.dataset.adj === 'reset') {
        imgAdjust = { brightness:100, contrast:100, invert:false };
        syncImgToolsUI(scope);
      } else if (btn.dataset.adj === 'invert') {
        imgAdjust.invert = !imgAdjust.invert;
        btn.classList.toggle('active', imgAdjust.invert);
      }
      applyImgFilter();
    };
  });
}

// Bấm ra ngoài popup thì tự đóng
document.addEventListener('click', (e) => {
  document.querySelectorAll('.img-slider-popup.show').forEach(p=>{
    if (!p.parentElement.contains(e.target)) p.classList.remove('show');
  });
});

/* ====================== NGHE TIM PHỔI (AUSCULTATION) ====================== */
let ausculState = { curPointIdx:null, seconds:0, timerHandle:null, running:false };

function fmtStopwatch(sec){
  const m = String(Math.floor(sec/60)).padStart(2,'0');
  const s = String(sec%60).padStart(2,'0');
  return m+':'+s;
}

function renderAusculSection(q){
  if (!q.ausculta || !q.ausculta.image || !q.ausculta.image.duong_dan) return '';
  const img = q.ausculta.image;
  const points = q.ausculta.points || [];
  let dotsHtml = '';
  points.forEach((p, idx)=>{
    dotsHtml += '<div class="auscul-marker-dot" data-idx="'+idx+'" style="left:'+p.x+'%;top:'+p.y+'%" title="'+esc(p.ten_vi_tri||('Vị trí '+(idx+1)))+'"></div>';
  });
  return '<div class="auscul-side-col">'
    + '<div class="auscul-image-box">'
    +   '<div class="auscul-img-wrap"><img src="'+esc(img.duong_dan)+'"><div class="auscul-markers" id="ausculMarkers">'+dotsHtml+'</div></div>'
    + '</div>'
    + '<div class="auscul-right-col">'
    +   '<div class="auscul-timer" id="ausculTimer">'
    +     '<i class="bi bi-stopwatch"></i> Đồng hồ thời gian:'
    +     '<span id="ausculTimerText">00:00</span>'
    +     '<button type="button" id="ausculTimerToggle" title="Tạm dừng/Tiếp tục"><i class="bi bi-pause-fill"></i></button>'
    +     '<button type="button" id="ausculTimerReset" title="Đặt lại"><i class="bi bi-arrow-counterclockwise"></i></button>'
    +   '</div>'
    +   '<div class="auscul-function-box">'
    +     '<div class="auscul-func-header">'
    +       '<span class="auscul-point-name" id="ausculPointName">-- Chọn vị trí nghe --</span>'
    +     '</div>'
    +     '<div class="auscul-func-body" id="ausculFuncBody"><div class="text-muted small">Bấm vào 1 chấm trên hình để nghe.</div></div>'
    +   '</div>'
    + '</div>'
    + '</div>';
}

function bindAusculEvents(q){
  const wrap = document.getElementById('ausculMarkers');
  if (!wrap) return;
  wrap.querySelectorAll('.auscul-marker-dot').forEach(dot=>{
    dot.onclick = () => chonViTriNghe(q, parseInt(dot.dataset.idx, 10));
  });
}

function chonViTriNghe(q, idx){
  ausculState.curPointIdx = idx;
  document.querySelectorAll('#ausculMarkers .auscul-marker-dot').forEach(d=>{
    d.classList.toggle('active', parseInt(d.dataset.idx,10) === idx);
  });
  const p = q.ausculta.points[idx];
  document.getElementById('ausculPointName').textContent = p.ten_vi_tri || ('Vị trí ' + (idx+1));

  const body = document.getElementById('ausculFuncBody');
  const options = [];
  if (p.loai === 'chuong' || p.loai === 'ca_hai') options.push(['chuong','Mặt chuông']);
  if (p.loai === 'mang'   || p.loai === 'ca_hai') options.push(['mang','Mặt màng']);

  if (!options.length) {
    body.innerHTML = '<div class="text-muted small">Vị trí này chưa có dữ liệu âm thanh.</div>';
    return;
  }
  let selHtml = '<select class="auscul-mat-select" id="ausculMatSelect">';
  options.forEach(([val,label])=> selHtml += '<option value="'+val+'">'+label+'</option>');
  selHtml += '</select>';

  body.innerHTML =
      '<div class="auscul-player">'
    +   selHtml
    +   '<button type="button" class="auscul-play-btn" id="ausculPlayBtn"><i class="bi bi-play-fill"></i></button>'
    +   '<div class="auscul-vol-wrap">'                                                              // MỚI
    +     '<button type="button" class="auscul-vol-btn" id="ausculVolBtn" title="Âm lượng"><i class="bi bi-volume-up-fill"></i></button>'  // MỚI
    +     '<div class="auscul-vol-popup" id="ausculVolPopup"><input type="range" min="0" max="100" value="100" id="ausculVolSlider"></div>' // MỚI
    +   '</div>'                                                                                      // MỚI
    +   '<input type="range" class="auscul-seek" id="ausculSeek" min="0" max="100" value="0">'
    +   '<span class="auscul-time" id="ausculTime">00:00 / 00:00</span>'
    +   '<audio id="ausculAudioEl" preload="metadata"></audio>'
    + '</div>';

  napAmThanhTheoMat(p, options[0][0]);
  document.getElementById('ausculMatSelect').onchange = (e) => napAmThanhTheoMat(p, e.target.value);
}

function napAmThanhTheoMat(p, mat){
  const audio = document.getElementById('ausculAudioEl');
  const src = mat === 'chuong' ? p.audio_chuong : p.audio_mang;
  audio.pause();
  audio.src = src || '';
  document.getElementById('ausculPlayBtn').innerHTML = '<i class="bi bi-play-fill"></i>';
  document.getElementById('ausculSeek').value = 0;
  document.getElementById('ausculTime').textContent = '00:00 / 00:00';
  bindAusculAudioPlayer();
}

function bindAusculAudioPlayer(){
  const audio   = document.getElementById('ausculAudioEl');
  const playBtn = document.getElementById('ausculPlayBtn');
  const seek    = document.getElementById('ausculSeek');
  const timeEl  = document.getElementById('ausculTime');
  const volBtn    = document.getElementById('ausculVolBtn');     // MỚI
  const volPopup  = document.getElementById('ausculVolPopup');   // MỚI
  const volSlider = document.getElementById('ausculVolSlider');  // MỚI

  audio.volume = (parseInt(volSlider.value, 10) || 100) / 100;   // MỚI: áp dụng volume hiện tại khi nạp audio mới

  playBtn.onclick = () => {
    if (!audio.src) return;
    if (audio.paused) { audio.play(); playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>'; }
    else { audio.pause(); playBtn.innerHTML = '<i class="bi bi-play-fill"></i>'; }
  };
  audio.onended = () => { playBtn.innerHTML = '<i class="bi bi-play-fill"></i>'; };
  audio.onloadedmetadata = () => {
    seek.max = Math.floor(audio.duration) || 0;
    timeEl.textContent = '00:00 / ' + fmtStopwatch(Math.floor(audio.duration)||0);
  };
  audio.ontimeupdate = () => {
    seek.value = Math.floor(audio.currentTime);
    timeEl.textContent = fmtStopwatch(Math.floor(audio.currentTime)) + ' / ' + fmtStopwatch(Math.floor(audio.duration)||0);
  };
  seek.oninput = () => { audio.currentTime = seek.value; };

  // MỚI: mở/đóng popup âm lượng
  volBtn.onclick = (e) => {
    e.stopPropagation();
    volPopup.classList.toggle('show');
  };

  // MỚI: kéo thanh trượt -> đổi âm lượng + đổi icon
  volSlider.oninput = () => {
    const v = parseInt(volSlider.value, 10);
    audio.volume = v / 100;
    volBtn.innerHTML = v == 0
      ? '<i class="bi bi-volume-mute-fill"></i>'
      : (v < 50 ? '<i class="bi bi-volume-down-fill"></i>' : '<i class="bi bi-volume-up-fill"></i>');
  };
}

function batDauDongHoNghe(){
  clearInterval(ausculState.timerHandle);
  ausculState.seconds = 0;
  ausculState.running = true;
  document.getElementById('ausculTimer').style.display = '';
  document.getElementById('ausculTimerText').textContent = '00:00';
  document.getElementById('ausculTimerToggle').innerHTML = '<i class="bi bi-pause-fill"></i>';
  ausculState.timerHandle = setInterval(()=>{
    ausculState.seconds++;
    document.getElementById('ausculTimerText').textContent = fmtStopwatch(ausculState.seconds);
  }, 1000);

  document.getElementById('ausculTimerToggle').onclick = () => {
    if (ausculState.running) {
      clearInterval(ausculState.timerHandle);
      ausculState.running = false;
      document.getElementById('ausculTimerToggle').innerHTML = '<i class="bi bi-play-fill"></i>';
    } else {
      ausculState.running = true;
      document.getElementById('ausculTimerToggle').innerHTML = '<i class="bi bi-pause-fill"></i>';
      ausculState.timerHandle = setInterval(()=>{
        ausculState.seconds++;
        document.getElementById('ausculTimerText').textContent = fmtStopwatch(ausculState.seconds);
      }, 1000);
    }
  };
  document.getElementById('ausculTimerReset').onclick = () => {
    ausculState.seconds = 0;
    document.getElementById('ausculTimerText').textContent = '00:00';
  };
}

function dungDongHoNgheKhiChuyenCau(){
  clearInterval(ausculState.timerHandle);
  ausculState = { curPointIdx:null, seconds:0, timerHandle:null, running:false };
}

document.addEventListener('click', (e) => {
  const volPopup = document.getElementById('ausculVolPopup');
  const volBtn = document.getElementById('ausculVolBtn');
  if (volPopup && volPopup.classList.contains('show') && !volPopup.contains(e.target) && e.target !== volBtn && !volBtn.contains(e.target)) {
    volPopup.classList.remove('show');
  }
});

function render(){
  dungDongHoNgheKhiChuyenCau();
  const q = EXAM_QUESTIONS[curIdx];
  document.getElementById('qCount').textContent = 'Câu ' + (curIdx+1) + '/' + N;

  if (q && q.kind === 'group') {
    if (curIdx !== lastGroupQuestionIdx) { curPartIdx = 0; lastGroupQuestionIdx = curIdx; } // THÊM
    renderGroupQuestion(q);
    return;
  }
  renderSingleQuestion(q);
}

// ==== Hàm render câu đơn (Single) ====
function renderSingleQuestion(q){
  const vignetteHtml = (vignetteHighlights[curIdx] !== undefined)
    ? vignetteHighlights[curIdx]
    : marked.parse(fixNewlines(q.vignette || ''));

  let textHtml = '';
  if ((q.vignette || '').trim() !== '' || vignetteHighlights[curIdx] !== undefined) {
    textHtml += '<div class="q-vignette">' + vignetteHtml + '</div>';
  }
  textHtml += '<div class="q-leadin">' + marked.parse(fixNewlines(q.lead_in || '')) + '</div>';
  const loaiCau = q.loai_cau || 'mot_dap_an';

  // THÊM MỚI: hiện mục tiêu học tập khi đã trả lời xong
  if (IS_LUYEN_TAP && q.learning_objective && daTraLoiDayDu(loaiCau, q.choices, answers[String(curIdx)])) {
    textHtml += '<div class="mb-3 p-2 bg-light border-start border-3 border-primary rounded-end" style="font-size:13.5px;">'
              + esc(q.learning_objective) + '</div>';
  }

  textHtml += renderCauTraLoiTN(loaiCau, q.choices, String(curIdx), q.giai_thich);

  let imageHtml = '';
  if (q.image_path) {
    imageHtml = '<div class="q-image-col">'
      + '<div style="width:100%;text-align:center;"><img src="' + esc(q.image_path) + '" class="q-zoomable-img" title="Bấm để phóng to"></div>'
      + (q.image_caption ? '<div class="img-caption">' + esc(q.image_caption) + '</div>' : '')
      + buildImgTools()
      + '</div>';
  }

  document.getElementById('qBody').innerHTML =
      '<div class="q-layout"><div class="q-text-col">' + textHtml + '</div>' + imageHtml + renderAusculSection(q) + '</div>';
  hideReferenceRangeColumns(document.getElementById('qBody'));

  ganSuKienTraLoiTN(document.getElementById('qBody'), loaiCau, String(curIdx), q.choices); // <-- SỬA

  imgAdjust = { brightness:100, contrast:100, invert:false };
  document.querySelectorAll('.q-zoomable-img').forEach(img=>{
      img.addEventListener('click', () => openLightbox(img.getAttribute('src')));

      function checkWideImage(){
          if (img.naturalWidth && img.naturalHeight && (img.naturalWidth / img.naturalHeight) > 2) {
              const col = img.closest('.q-image-col');
              if (col) col.classList.add('wide-img');
          }
      }
      if (img.complete && img.naturalWidth) checkWideImage();
      else img.addEventListener('load', checkWideImage);
  });
  bindImgTools(document.getElementById('qBody'));
  applyImgFilter();

  bindAusculEvents(q);
  if (q.ausculta && q.ausculta.image && q.ausculta.image.duong_dan) {
    batDauDongHoNghe();
  }

  renderLabPanelForCurrentQuestion(q);

  document.getElementById('btnPrev').disabled = (curIdx===0);
  document.getElementById('btnNext').disabled = (curIdx===N-1);
  document.getElementById('btnFlag').classList.toggle('flag-active', !!flagged[String(curIdx)]);
  renderProgress();
  loadNoteForCurrent();
}

// ==== THÊM MỚI: tìm ảnh dùng chung giữa các ý trong 1 case study (ảnh xuất hiện ở >=2 ý) ====
function layAnhChungNhom(q){
  if (!q || !q.parts || !q.parts.length) return null;
  const counts = {};
  q.parts.forEach(p => { if (p.image_path) counts[p.image_path] = (counts[p.image_path]||0) + 1; });
  let best = null, bestCount = 1;
  Object.keys(counts).forEach(k => { if (counts[k] > bestCount) { best = k; bestCount = counts[k]; } });
  return best; // null nếu không có ảnh nào lặp lại từ 2 ý trở lên
}

// ==== THÊM MỚI: Hàm render câu nhóm (Group / Case Study) ====
function renderGroupQuestion(q){
  chuanHoaGroupCase(q);

  const commonImg = layAnhChungNhom(q);
  const parts = q.parts || [];
  if (curPartIdx >= parts.length) curPartIdx = 0;
  const j = curPartIdx;
  const p = parts[j];
  const key = curIdx + '_' + j;

  let html = '';
  if (q.group_stem) html += '<div class="q-vignette">' + marked.parse(fixNewlines(q.group_stem)) + '</div>';

  html += '<div style="border-radius:8px;margin-bottom:14px;">';

  // THÊM: header trong khung — nhãn bên trái, điều hướng Ý trước/Ý sau bên phải
  html += '<div class="d-flex justify-content-between align-items-center mb-2">'
        + '<div class="fw-bold" style="color:var(--primary)">Câu ' + (curIdx+1) + '.' + (j+1) + '</div>'
        + '<div class="d-flex align-items-center gap-2">'
        +   '<button type="button" class="btn btn-secondary btn-sm" id="btnPartPrev" style="padding:4px 10px;font-size:12.5px;"' + (j===0?' disabled':'') + '><i class="bi bi-chevron-left"></i> Trước</button>'
        +   '<span style="font-size:13px;color:#666;min-width:36px;text-align:center;">' + (j+1) + '/' + parts.length + '</span>'
        +   '<button type="button" class="btn btn-secondary btn-sm" id="btnPartNext" style="padding:4px 10px;font-size:12.5px;"' + (j===parts.length-1?' disabled':'') + '>Sau <i class="bi bi-chevron-right"></i></button>'
        + '</div>'
        + '</div>';

  if (p.image_path && p.image_path !== commonImg) {
    html += '<div style="text-align:center;margin-bottom:10px;"><img src="' + esc(p.image_path) + '" style="max-width:100%;border-radius:6px;"></div>';
  }
  html += '<div class="q-leadin">' + marked.parse(fixNewlines(p.lead_in || '')) + '</div>';
  const loaiCauPart = p.loai_cau || 'mot_dap_an';
  html += renderCauTraLoiTN(loaiCauPart, p.choices, key, p.giai_thich); // <-- SỬA
  html += '</div>';

  let commonImageHtml = '';
  if (commonImg) {
    commonImageHtml = '<div class="q-image-col">'
      + '<div style="width:100%;text-align:center;"><img src="' + esc(commonImg) + '" class="q-zoomable-img" title="Bấm để phóng to"></div>'
      + '<div class="img-caption">Hình dùng chung cho các ý hỏi trong câu này</div>'
      + buildImgTools()
      + '</div>';
  }

  document.getElementById('qBody').innerHTML =
      '<div class="q-layout"><div class="q-text-col">' + html + '</div>' + commonImageHtml + '</div>';
  ganSuKienTraLoiTN(document.getElementById('qBody'), loaiCauPart, key, p.choices); // <-- SỬA

  document.getElementById('btnPartPrev').onclick = () => { if (curPartIdx>0){ curPartIdx--; renderGroupQuestion(q); } };
  document.getElementById('btnPartNext').onclick = () => { if (curPartIdx<parts.length-1){ curPartIdx++; renderGroupQuestion(q); } };

  if (commonImg) {
    imgAdjust = { brightness:100, contrast:100, invert:false };
    document.querySelectorAll('#qBody .q-zoomable-img').forEach(img=>{
      img.addEventListener('click', () => openLightbox(img.getAttribute('src')));
      function checkWideImage(){
        if (img.naturalWidth && img.naturalHeight && (img.naturalWidth / img.naturalHeight) > 2) {
          const col = img.closest('.q-image-col');
          if (col) col.classList.add('wide-img');
        }
      }
      if (img.complete && img.naturalWidth) checkWideImage();
      else img.addEventListener('load', checkWideImage);
    });
    bindImgTools(document.getElementById('qBody'));
    applyImgFilter();
  }

  document.getElementById('btnPrev').disabled = (curIdx===0);
  document.getElementById('btnNext').disabled = (curIdx===N-1);
  document.getElementById('btnFlag').classList.toggle('flag-active', !!flagged[String(curIdx)]);
  renderProgress();
  loadNoteForCurrent();
}

// ==== THÊM MỚI: Chọn đáp án cho từng ý trong câu nhóm ====
function chonDapAnNhom(key, optId){
  answers[key] = optId;
  render();
  fetch('?action=save_answer', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'sid='+SID+'&idx='+encodeURIComponent(key)+'&option_id='+encodeURIComponent(optId)
  }).then(r=>r.json()).then(d=>{
    if (d.expired) { doSubmit(); }
  }).catch(()=>{});
}
function chonDapAn(optId){
  answers[String(curIdx)] = optId;
  render();
  fetch('?action=save_answer', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'sid='+SID+'&idx='+curIdx+'&option_id='+encodeURIComponent(optId)
  }).then(r=>r.json()).then(d=>{
    if (d.expired) { doSubmit(); }
  }).catch(()=>{});
}
// ==== THÊM MỚI: chọn/bỏ chọn 1 đáp án cho câu "Nhiều đáp án đúng" ====
function toggleDapAnNhieu(key, optId){
  let cur = Array.isArray(answers[key]) ? answers[key].slice() : [];
  const pos = cur.indexOf(optId);
  if (pos === -1) cur.push(optId); else cur.splice(pos, 1);
  answers[key] = cur;
  render();
  fetch('?action=save_answer', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'sid='+SID+'&idx='+encodeURIComponent(key)+'&option_id='+encodeURIComponent(JSON.stringify(cur))
  }).then(r=>r.json()).then(d=>{ if (d.expired) doSubmit(); }).catch(()=>{});
}

// ==== THÊM MỚI: chọn Đúng/Sai cho 1 phương án của câu "Đúng/Sai" ====
function chonDungSai(key, optId, giaTri){
  let cur = (answers[key] && typeof answers[key]==='object' && !Array.isArray(answers[key])) ? Object.assign({}, answers[key]) : {};
  cur[optId] = giaTri; // 'dung' hoặc 'sai'
  answers[key] = cur;
  render();
  fetch('?action=save_answer', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'sid='+SID+'&idx='+encodeURIComponent(key)+'&option_id='+encodeURIComponent(JSON.stringify(cur))
  }).then(r=>r.json()).then(d=>{ if (d.expired) doSubmit(); }).catch(()=>{});
}

// ==== THÊM MỚI: hiện đáp án đúng/sai + khoá lựa chọn ngay khi đã trả lời (chế độ luyện tập) ====
function renderKetQuaLuyenTap(loaiCau, choices, userAnswer){
  if (loaiCau === 'nhieu_dap_an') {
    let html = '';
    (choices||[]).forEach(c=>{
      const selected = Array.isArray(userAnswer) && userAnswer.includes(c.option_id);
      const cls = c.is_correct ? 'lt-correct' : (selected ? 'lt-wrong' : '');
      html += '<label class="choice-option ' + cls + '"><input type="checkbox" disabled ' + (selected?'checked':'') + '>'
            + '<div><b>' + esc(c.option_id) + '.</b> ' + esc(c.text)
            + (c.is_correct ? ' <span class="badge bg-success">Đáp án đúng</span>' : (selected ? ' <span class="badge bg-danger">Bạn đã chọn</span>' : ''))
            + '</div></label>';
    });
    return html;
  }
  if (loaiCau === 'dung_sai') {
    const map = (userAnswer && typeof userAnswer==='object' && !Array.isArray(userAnswer)) ? userAnswer : {};
    let html = '';
    (choices||[]).forEach(c=>{
      const userVal = map[c.option_id] || null;
      const correctVal = c.is_correct ? 'dung' : 'sai';
      const dungY = userVal === correctVal;
      html += '<div class="choice-option ' + (dungY?'lt-correct':'lt-wrong') + '"><b>' + esc(String(c.option_id).toLowerCase()) + ')</b> ' + esc(c.text)
            + ' <span class="' + (dungY?'tag-correct':'tag-wrong') + '">Bạn chọn: ' + (userVal==='dung'?'Đúng':(userVal==='sai'?'Sai':'(bỏ trống)')) + '</span>'
            + ' <span class="badge ' + (c.is_correct?'bg-success':'bg-danger') + '">Đáp án: ' + (c.is_correct?'Đúng':'Sai') + '</span></div>';
    });
    return html;
  }
  // mot_dap_an
  let html = '';
  (choices||[]).forEach(c=>{
    const cls = c.is_correct ? 'lt-correct' : (c.option_id===userAnswer ? 'lt-wrong' : '');
    html += '<label class="choice-option ' + cls + '"><input type="radio" disabled ' + (c.option_id===userAnswer?'checked':'') + '>'
          + '<div><b>' + esc(c.option_id) + '.</b> ' + esc(c.text)
          + (c.image ? '<div class="mt-2 mb-2"><img src="' + esc(c.image) + '" style="border-radius:6px;max-width:500px;display:block;"></div>' : '')
          + (c.is_correct ? ' <span class="badge bg-success">Đáp án đúng</span>' : (c.option_id===userAnswer ? ' <span class="badge bg-danger">Bạn đã chọn</span>' : ''))
          + (c.explanation ? '<div class="small text-muted mt-1">' + marked.parse(fixNewlines(c.explanation)) + '</div>' : '')
          + '</div></label>';
  });
  return html;
}

// ==== THÊM MỚI: render các phương án trả lời theo đúng loại câu hỏi (dùng chung cho câu đơn & từng ý case study) ====
function renderCauTraLoiTN(loaiCau, choices, key, giaiThich){   // <-- SỬA: thêm tham số giaiThich
  if (IS_LUYEN_TAP && daTraLoiDayDu(loaiCau, choices, answers[key])) {   // <-- THÊM MỚI: đã trả lời -> hiện đáp án + giải thích, khoá lại
    let html = renderKetQuaLuyenTap(loaiCau, choices, answers[key]);
    if (giaiThich) html += '<div class="small text-muted mt-2 p-2 bg-light rounded"><b>Giải thích:</b> ' + marked.parse(fixNewlines(giaiThich)) + '</div>';
    return html;
  }
  if (loaiCau === 'nhieu_dap_an') {
    const selArr = Array.isArray(answers[key]) ? answers[key] : [];
    let html = '';
    (choices||[]).forEach(c=>{
      const sel = selArr.includes(c.option_id);
      html += '<label class="choice-option' + (sel?' selected':'') + '" data-opt="' + esc(c.option_id) + '">'
            + '<input type="checkbox" value="' + esc(c.option_id) + '" ' + (sel?'checked':'') + '>'
            + '<div><b>' + esc(c.option_id) + '.</b> ' + esc(c.text) + '</div></label>';
    });
    return html;
  }
  if (loaiCau === 'dung_sai') {
    const curMap = (answers[key] && typeof answers[key] === 'object' && !Array.isArray(answers[key])) ? answers[key] : {};
    let html = '';
    (choices||[]).forEach(c=>{
      const val = curMap[c.option_id] || '';
      html += '<div class="choice-option ds-row" data-opt="' + esc(c.option_id) + '">'
            +   '<div class="flex-grow-1"><b>' + esc(String(c.option_id).toLowerCase()) + ')</b> ' + esc(c.text) + '</div>'
            +   '<div class="ds-toggle">'
            +     '<label><input type="radio" style="margin-top:0" name="ds_' + key + '_' + esc(c.option_id) + '" value="dung" ' + (val==='dung'?'checked':'') + '> Đúng</label>'
            +     '<label><input type="radio" style="margin-top:0" name="ds_' + key + '_' + esc(c.option_id) + '" value="sai" '  + (val==='sai'?'checked':'')  + '> Sai</label>'
            +   '</div></div>';
    });
    return html;
  }
  // mot_dap_an (mặc định — giữ đúng markup cũ)
  let html = '';
  (choices||[]).forEach(c=>{
    const sel = answers[key] === c.option_id;
    html += '<label class="choice-option' + (sel?' selected':'') + '" data-opt="' + esc(c.option_id) + '">'
          + '<input type="radio" name="opt_' + key + '" value="' + esc(c.option_id) + '" ' + (sel?'checked':'') + '>'
          + '<div><b>' + esc(c.option_id) + '.</b> ' + esc(c.text) + '</div></label>';
  });
  return html;
}

// ==== THÊM MỚI: gắn sự kiện chọn đáp án theo đúng loại câu hỏi ====
function ganSuKienTraLoiTN(scope, loaiCau, key, choices){   // <-- SỬA: thêm tham số choices
  if (IS_LUYEN_TAP && daTraLoiDayDu(loaiCau, choices, answers[key])) return; // <-- THÊM MỚI: đã hiện đáp án thì khoá, không gắn sự kiện nữa
  if (loaiCau === 'nhieu_dap_an') {
    scope.querySelectorAll('.choice-option').forEach(el=>{
      el.addEventListener('click', () => toggleDapAnNhieu(key, el.dataset.opt));
    });
  } else if (loaiCau === 'dung_sai') {
    scope.querySelectorAll('.ds-row input[type="radio"]').forEach(inp=>{
      inp.addEventListener('change', function(){
        chonDungSai(key, this.closest('.ds-row').dataset.opt, this.value);
      });
    });
  } else {
    scope.querySelectorAll('.choice-option').forEach(el=>{
      el.addEventListener('click', () => chonDapAnNhom(key, el.dataset.opt));
    });
  }
}

// ==== THÊM MỚI: kiểm tra 1 câu (theo loại) đã trả lời đầy đủ hay chưa ====
function daTraLoiDayDu(loaiCau, choices, val){
  if (loaiCau === 'nhieu_dap_an') return Array.isArray(val) && val.length > 0;
  if (loaiCau === 'dung_sai') {
    if (!val || typeof val !== 'object' || Array.isArray(val)) return false;
    return (choices||[]).every(c => val[c.option_id] === 'dung' || val[c.option_id] === 'sai');
  }
  return !!val;
}
// ==== THÊM MỚI: kiểm tra 1 câu (theo loại) đã trả lời ĐÚNG hay chưa (dùng cho chế độ luyện tập) ====
function dapAnDung(loaiCau, choices, val){
  if (loaiCau === 'nhieu_dap_an') {
    const correctSet = (choices||[]).filter(c=>c.is_correct).map(c=>c.option_id).sort();
    const userSet = Array.isArray(val) ? val.slice().sort() : [];
    return correctSet.length > 0 && JSON.stringify(correctSet) === JSON.stringify(userSet);
  }
  if (loaiCau === 'dung_sai') {
    if (!val || typeof val !== 'object' || Array.isArray(val)) return false;
    return (choices||[]).every(c => {
      const correctVal = c.is_correct ? 'dung' : 'sai';
      return val[c.option_id] === correctVal;
    });
  }
  // mot_dap_an
  const correctObj = (choices||[]).find(c=>c.is_correct);
  const correctId = correctObj ? correctObj.option_id : null;
  return correctId !== null && val === correctId;
}

document.getElementById('btnPrev').onclick = ()=>{ if(curIdx>0){curIdx--;render();} };
document.getElementById('btnNext').onclick = ()=>{ if(curIdx<N-1){curIdx++;render();} };

function doSubmit(){
  document.getElementById('submitForm').submit();
}
if (document.getElementById('btnSubmit')) {   // <-- THÊM MỚI: chế độ luyện tập không có nút này
  document.getElementById('btnSubmit').onclick = ()=>{
    // Đếm chính xác số câu đã hoàn thành (câu nhóm phải làm đủ mọi ý)
    let answeredCount = 0;
    for (let i=0; i<N; i++){
      const q = EXAM_QUESTIONS[i];
      const ok = (q && q.kind === 'group')
        ? (q.parts && q.parts.every((p, j) => daTraLoiDayDu(p.loai_cau || 'mot_dap_an', p.choices, answers[i + '_' + j])))
        : daTraLoiDayDu(q.loai_cau || 'mot_dap_an', q.choices, answers[String(i)]);
      if (ok) answeredCount++;
    }
    const chuaLam = N - answeredCount;
    const daDanhDau = Object.keys(flagged).length;
    let msg = '';
    if (chuaLam > 0) msg += 'Bạn còn ' + chuaLam + ' câu chưa hoàn thành. ';
    if (daDanhDau > 0) msg += 'Bạn đã đánh dấu ' + daDanhDau + ' câu để xem lại. ';
    msg += 'Bạn có chắc chắn muốn nộp bài?';
    if (confirm(msg)) doSubmit();
  };
}

/* Đếm ngược thời gian */
function fmt(sec){
  const h = String(Math.floor(sec/3600)).padStart(2,'0');
  const m = String(Math.floor(sec%3600/60)).padStart(2,'0');
  const s = String(sec%60).padStart(2,'0');
  return h+':'+m+':'+s;
}
function tick(){
  const remain = Math.max(0, Math.round((END_TIME_MS - Date.now())/1000));
  document.getElementById('timerText').textContent = fmt(remain);
  if (remain <= 300) document.getElementById('timerBox').classList.add('warn');
  if (remain <= 0) { clearInterval(timerHandle); doSubmit(); }
}
const timerHandle = setInterval(tick, 1000);
tick();

const imgLightbox = document.getElementById('imgLightbox');
const lbImage = document.getElementById('lbImage');
const lbImageWrap = imgLightbox.querySelector('.lb-image-wrap');

let lbScale = 1;
let lbTx = 0, lbTy = 0;
let lbDragging = false, lbStartX = 0, lbStartY = 0, lbStartTx = 0, lbStartTy = 0;

function updateLbTransform(){
  lbImage.style.transform = 'translate(' + lbTx + 'px,' + lbTy + 'px) scale(' + lbScale + ')';
}

function openLightbox(src){
  lbImage.src = src;
  lbScale = 1;
  lbImage.style.transform = 'scale(1)';
  applyImgFilter();
  syncImgToolsUI(imgLightbox);   // <-- thêm dòng này
  imgLightbox.classList.add('show');
}
document.getElementById('lbClose').onclick = () => imgLightbox.classList.remove('show');
imgLightbox.addEventListener('click', (e)=>{ if (e.target === imgLightbox) imgLightbox.classList.remove('show'); });
document.getElementById('lbZoomIn').onclick = () => { lbScale = Math.min(lbScale+0.25,4); updateLbTransform(); };
document.getElementById('lbZoomOut').onclick = () => { lbScale = Math.max(lbScale-0.25,0.5); updateLbTransform(); };
bindImgTools(imgLightbox);

// Kéo ảnh bằng chuột (pan) khi đã phóng to
lbImage.addEventListener('mousedown', (e) => {
  lbDragging = true;
  lbImageWrap.classList.add('dragging');
  lbStartX = e.clientX; lbStartY = e.clientY;
  lbStartTx = lbTx; lbStartTy = lbTy;
  e.preventDefault();
});
document.addEventListener('mousemove', (e) => {
  if (!lbDragging) return;
  lbTx = lbStartTx + (e.clientX - lbStartX);
  lbTy = lbStartTy + (e.clientY - lbStartY);
  updateLbTransform();
});
document.addEventListener('mouseup', () => {
  lbDragging = false;
  lbImageWrap.classList.remove('dragging');
});

// ==== THÊM MỚI: kéo/pinch-zoom ảnh bằng tay trên điện thoại (touch) ====
let lbTouchStartDist = null, lbTouchStartScale = 1;
let lbTouchStartX = 0, lbTouchStartY = 0, lbTouchStartTx = 0, lbTouchStartTy = 0, lbTouchDragging = false;
function lbTouchDist(t1, t2){ return Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY); }
lbImageWrap.addEventListener('touchstart', (e) => {
  if (e.touches.length === 2) {
    lbTouchStartDist = lbTouchDist(e.touches[0], e.touches[1]);
    lbTouchStartScale = lbScale;
  } else if (e.touches.length === 1) {
    lbTouchDragging = true;
    lbTouchStartX = e.touches[0].clientX; lbTouchStartY = e.touches[0].clientY;
    lbTouchStartTx = lbTx; lbTouchStartTy = lbTy;
  }
}, { passive:true });
lbImageWrap.addEventListener('touchmove', (e) => {
  if (e.touches.length === 2 && lbTouchStartDist) {
    const d = lbTouchDist(e.touches[0], e.touches[1]);
    lbScale = Math.min(4, Math.max(0.5, lbTouchStartScale * (d / lbTouchStartDist)));
    updateLbTransform();
  } else if (e.touches.length === 1 && lbTouchDragging && lbScale > 1) {
    lbTx = lbTouchStartTx + (e.touches[0].clientX - lbTouchStartX);
    lbTy = lbTouchStartTy + (e.touches[0].clientY - lbTouchStartY);
    updateLbTransform();
  }
}, { passive:true });
lbImageWrap.addEventListener('touchend', () => { lbTouchStartDist = null; lbTouchDragging = false; });

/* ==== Nháp (Notes) ==== */
const notesPanel = document.getElementById('notesPanel');
const notesText  = document.getElementById('notesText');
const NOTES_KEY  = 'nhap_' + SID;
let notesByQuestion = {};
try { notesByQuestion = JSON.parse(sessionStorage.getItem(NOTES_KEY) || '{}'); } catch(e){ notesByQuestion = {}; }

function loadNoteForCurrent(){
  notesText.value = notesByQuestion[String(curIdx)] || '';
}
notesText.addEventListener('input', () => {
  notesByQuestion[String(curIdx)] = notesText.value;
  sessionStorage.setItem(NOTES_KEY, JSON.stringify(notesByQuestion));
});
document.getElementById('btnNotes').onclick = () => {
  notesPanel.classList.toggle('show');
  infoPopover.classList.remove('show');   // <-- thêm dòng này
};
document.getElementById('notesClose').onclick = () => notesPanel.classList.remove('show');
document.getElementById('btnFlag').onclick = () => {
  toggleFlag();
  infoPopover.classList.remove('show');
};

/* ==== Máy tính (Calculator) ==== */
const calcPanel   = document.getElementById('calcPanel');
const calcDisplay = document.getElementById('calcDisplay');
const calcHistory = document.getElementById('calcHistory'); // [MỚI] Thêm phần tử hiển thị lịch sử

function makeDraggable(panel, handle){
  let dragging = false, offsetX = 0, offsetY = 0;
  handle.style.cursor = 'move';
  handle.addEventListener('mousedown', (e)=>{
    dragging = true;
    const rect = panel.getBoundingClientRect();
    panel.style.left = rect.left + 'px';
    panel.style.top = rect.top + 'px';
    panel.style.right = 'auto';
    offsetX = e.clientX - rect.left;
    offsetY = e.clientY - rect.top;
    document.body.style.userSelect = 'none';
  });
  document.addEventListener('mousemove', (e)=>{
    if (!dragging) return;
    panel.style.left = Math.max(0, e.clientX - offsetX) + 'px';
    panel.style.top = Math.max(0, e.clientY - offsetY) + 'px';
  });
  document.addEventListener('mouseup', ()=>{ dragging = false; document.body.style.userSelect = ''; });
}
// <-- SỬA: chỉ cho phép kéo (drag) panel bằng chuột khi màn hình đủ rộng (desktop);
// trên di động panel luôn hiển thị dạng bottom-sheet cố định theo CSS.
if (window.innerWidth > 768) {
  makeDraggable(notesPanel, notesPanel.querySelector('.fp-header'));
  makeDraggable(calcPanel, calcPanel.querySelector('.fp-header'));
}

let calcMemory = 0;
let calcDegMode = true;   // true = độ (DEG), false = radian (RAD)
let calcLastAns = 0;      // lưu kết quả lần bấm "=" gần nhất

function toDisplayExpr(expr){
  return expr.replace(/\*/g, '×').replace(/\//g, '÷');
}
function updateMemIndicator(){
  document.getElementById('calcMemIndicator').style.display = calcMemory !== 0 ? 'block' : 'none';
}
function currentCalcValue(){
  try {
    const rawExpr = calcExpr.replace(/×/g,'*').replace(/÷/g,'/').replace(/\^/g,'**'); // thêm .replace(/\^/g,'**')
    if (!rawExpr) return 0;
    const v = Function('"use strict";return (' + rawExpr + ')')();
    return isFinite(v) ? v : 0;
  } catch(e){ return 0; }
}

let calcExpr = '';
let isEvaluated = false; // [MỚI] Biến ghi nhớ trạng thái vừa bấm dấu =

document.getElementById('btnCalc').onclick = () => {
  calcPanel.classList.toggle('show');
  infoPopover.classList.remove('show');
};
document.getElementById('calcClose').onclick = () => calcPanel.classList.remove('show');

function handleCalcInput(v){
  if (v === 'C') {
    calcExpr = '';
    calcHistory.textContent = '';
    isEvaluated = false;
  }
  else if (v === 'back') {
    if (!isEvaluated) calcExpr = calcExpr.slice(0, -1);
  }
  else if (v === '(' || v === ')') {
    if (isEvaluated) { calcExpr = ''; calcHistory.textContent = ''; isEvaluated = false; }
    calcExpr += v;
  }
  else if (v === 'sqrt') {
    const val = currentCalcValue();
    calcHistory.textContent = '√(' + (toDisplayExpr(calcExpr) || '0') + ') =';
    calcExpr = String(Math.sqrt(Math.abs(val)));
    isEvaluated = true;
  }
  else if (v === '+/-') {
    const mNeg = calcExpr.match(/\(-([\d.]+)\)$/);
    if (mNeg) {
      calcExpr = calcExpr.slice(0, -mNeg[0].length) + mNeg[1];
    } else {
      const mNum = calcExpr.match(/([\d.]+)$/);
      if (mNum) calcExpr = calcExpr.slice(0, -mNum[0].length) + '(-' + mNum[0] + ')';
    }
  }
  else if (v === 'mc') { calcMemory = 0; updateMemIndicator(); }
  else if (v === 'mr') {
    if (isEvaluated) { calcExpr = ''; isEvaluated = false; }
    calcExpr += String(calcMemory);
  }
  else if (v === 'm+') { calcMemory += currentCalcValue(); updateMemIndicator(); }
  else if (v === 'm-') { calcMemory -= currentCalcValue(); updateMemIndicator(); }
  else if (v === '=') {
    if (calcExpr && !isEvaluated) {
      try {
        let rawExpr = calcExpr.replace(/×/g, '*').replace(/÷/g, '/').replace(/\^/g, '**'); // thêm replace ^
        const openC = (rawExpr.match(/\(/g)||[]).length;
        const closeC = (rawExpr.match(/\)/g)||[]).length;
        if (openC > closeC) rawExpr += ')'.repeat(openC - closeC);
        const res = String(Function('"use strict";return (' + rawExpr + ')')());
        calcHistory.textContent = toDisplayExpr(calcExpr) + ' =';
        calcExpr = res;
        isEvaluated = true;
        calcLastAns = parseFloat(res) || 0;   // thêm dòng này
      } catch(e) {
        calcHistory.textContent = toDisplayExpr(calcExpr) + ' =';
        calcExpr = 'Lỗi';
        isEvaluated = true;
      }
    }
  }
  else if (v === 'deg') {
    calcDegMode = !calcDegMode;
    document.getElementById('calcDegBtn').textContent = calcDegMode ? 'DEG' : 'RAD';
  }
  else if (v === 'sin' || v === 'cos' || v === 'tan') {
    const val = currentCalcValue();
    const rad = calcDegMode ? val * Math.PI / 180 : val;
    const fn = { sin: Math.sin, cos: Math.cos, tan: Math.tan }[v];
    calcHistory.textContent = v + '(' + (toDisplayExpr(calcExpr) || '0') + ') =';
    calcExpr = String(fn(rad));
    isEvaluated = true;
  }
  else if (v === 'asin' || v === 'acos' || v === 'atan') {
    const val = currentCalcValue();
    const fn = { asin: Math.asin, acos: Math.acos, atan: Math.atan }[v];
    let res = fn(val);
    if (calcDegMode) res = res * 180 / Math.PI;
    calcHistory.textContent = v + '(' + (toDisplayExpr(calcExpr) || '0') + ') =';
    calcExpr = String(res);
    isEvaluated = true;
  }
  else if (v === 'log') {
    const val = currentCalcValue();
    calcHistory.textContent = 'log(' + (toDisplayExpr(calcExpr) || '0') + ') =';
    calcExpr = String(Math.log10(val));
    isEvaluated = true;
  }
  else if (v === 'ln') {
    const val = currentCalcValue();
    calcHistory.textContent = 'ln(' + (toDisplayExpr(calcExpr) || '0') + ') =';
    calcExpr = String(Math.log(val));
    isEvaluated = true;
  }
  else if (v === 'sq') {
    const val = currentCalcValue();
    calcHistory.textContent = '(' + (toDisplayExpr(calcExpr) || '0') + ')² =';
    calcExpr = String(val * val);
    isEvaluated = true;
  }
  else if (v === 'pi') {
    if (isEvaluated) { calcExpr = ''; calcHistory.textContent = ''; isEvaluated = false; }
    calcExpr += String(Math.PI);
  }
  else if (v === 'econst') {
    if (isEvaluated) { calcExpr = ''; calcHistory.textContent = ''; isEvaluated = false; }
    calcExpr += String(Math.E);
  }
  else if (v === 'ans') {
    if (isEvaluated) { calcExpr = ''; calcHistory.textContent = ''; isEvaluated = false; }
    calcExpr += String(calcLastAns);
  }
  else if (v === '^') {
    if (isEvaluated) isEvaluated = false;
    calcExpr += '^';
  }
  else {
    if (isEvaluated) {
      if (['+', '-', '*', '/', '%'].includes(v)) {
        isEvaluated = false;
      } else {
        calcExpr = '';
        calcHistory.textContent = '';
        isEvaluated = false;
      }
    }
    calcExpr += v;
  }
  calcDisplay.textContent = toDisplayExpr(calcExpr) || '0';
}

document.querySelectorAll('#calcPanel [data-calc]').forEach(btn => {
  btn.addEventListener('click', () => handleCalcInput(btn.dataset.calc));
});

// ==== Hỗ trợ nhập máy tính bằng bàn phím ====
const CALC_KEY_MAP = {
  '0':'0','1':'1','2':'2','3':'3','4':'4','5':'5','6':'6','7':'7','8':'8','9':'9',
  '.':'.', '+':'+', '-':'-', '*':'*', '/':'/', '%':'%', '^':'^',   // thêm '^':'^'
  '(':'(', ')':')',
  'Enter':'=', '=':'=',
  'Backspace':'back',
  'Escape':'C', 'c':'C', 'C':'C'
};

document.addEventListener('keydown', (e) => {
  if (!calcPanel.classList.contains('show')) return; // chỉ nhận phím khi máy tính đang mở

  const key = e.key;
  if (!(key in CALC_KEY_MAP)) return;

  // tránh xung đột: không bắt phím khi đang gõ trong ô nháp hoặc ô tìm kiếm...
  const tag = (e.target.tagName || '').toLowerCase();
  if (tag === 'textarea' || tag === 'input') return;

  e.preventDefault();
  handleCalcInput(CALC_KEY_MAP[key]);
});

/* ==== Bảng tham chiếu xét nghiệm (Lab Values) ==== */
const thiSplit = document.getElementById('thiSplit');
document.getElementById('btnLab').onclick = () => {
  thiSplit.classList.toggle('lab-open');
  infoPopover.classList.remove('show');
};
document.getElementById('labClose').onclick = () => thiSplit.classList.remove('lab-open');
const LAB_TABLES = <?= json_encode($labTablesJs, JSON_UNESCAPED_UNICODE) ?>;
let currentLabTableId = LAB_TABLES.length ? LAB_TABLES[0].id : null;

/* ===== BỔ SUNG: BẢNG XÉT NGHIỆM RIÊNG THEO CÂU (nếu câu có ausculta/xetnghiem tự thiết lập) ===== */
let customXnActive = false;
let customXnInstances = [];
let currentCustomXnIdx = 0;
let currentMergeOverrides = {}; // { [tableId]: { [chiSoKeyLower]: {don_vi, tham_chieu, gia_tri} } }
let currentMergeExtras = {};    // { [tableId]: [ {nhom, chi_so, don_vi, tham_chieu} ] } — chỉ số không có trong bảng gốc

// Tìm vị trí (rank) của 1 chỉ số trong bảng tham chiếu gốc để sắp xếp lại
function timRankChiSoTrongBangGoc(sourceTable, chiSo){
  if (!sourceTable || !sourceTable.data) return null;
  let rank = 0;
  for (const g of sourceTable.data) {
    if (g.is_single) {
      if ((g.ten_nhom||'').trim().toLowerCase() === (chiSo||'').trim().toLowerCase()) return rank;
      rank++;
    } else {
      for (const en of (g.entries||[])) {
        const vals = (en.values && en.values.length) ? en.values : [];
        if (vals.some(v => (v||'').trim().toLowerCase() === (chiSo||'').trim().toLowerCase())) return rank;
        rank++;
      }
    }
  }
  return null;
}

// Sắp xếp các chỉ số kết quả trong 1 bảng theo đúng thứ tự bảng gốc, chỉ số không khớp xếp sau cùng (giữ nguyên thứ tự ban đầu)
function sapXepKetQuaTheoBangGoc(instance){
  const sourceTable = instance.source_id ? LAB_TABLES.find(t => t.id === instance.source_id) : null;
  const list = (instance.ket_qua || []).map((kq, i) => ({
    kq, idx: i,
    rank: sourceTable ? timRankChiSoTrongBangGoc(sourceTable, kq.chi_so) : null,
  }));
  list.sort((a,b) => {
    const ar = (a.rank === null) ? Infinity : a.rank;
    const br = (b.rank === null) ? Infinity : b.rank;
    return (ar !== br) ? (ar - br) : (a.idx - b.idx);
  });
  return list.map(x => x.kq);
}

// Sắp xếp các bảng (instance) theo đúng thứ tự xuất hiện trong danh sách bảng gốc, bảng không khớp xếp sau cùng
function sapXepInstancesTheoBangGoc(instances){
  const withRank = instances.map((inst, i) => {
    let rank = null;
    if (inst.source_id) {
      const pos = LAB_TABLES.findIndex(t => t.id === inst.source_id);
      if (pos !== -1) rank = pos;
    }
    return { inst, idx: i, rank };
  });
  withRank.sort((a,b) => {
    const ar = (a.rank === null) ? Infinity : a.rank;
    const br = (b.rank === null) ? Infinity : b.rank;
    return (ar !== br) ? (ar - br) : (a.idx - b.idx);
  });
  return withRank.map(x => x.inst);
}

function chuanBiCustomXn(q){
  const raw = (q.xetnghiem && q.xetnghiem.length) ? q.xetnghiem : [];
  currentMergeOverrides = {};
  currentMergeExtras = {};

  // Bảng nào có ÍT NHẤT 1 chỉ số có kết quả -> giữ hành vi cũ (tab riêng)
  // Bảng nào TẤT CẢ chỉ số đều không có kết quả VÀ có source_id -> gộp vào bảng gốc
  const withResult = [];
  const mergeOnly   = [];
  raw.forEach(inst => {
    const coKQ = (inst.ket_qua||[]).some(kq => !isEmptyVal(kq.gia_tri));
    if (coKQ) withResult.push(inst);
    else if (inst.source_id) mergeOnly.push(inst);
    else withResult.push(inst); // không rõ bảng gốc để gộp -> vẫn hiện tab riêng
  });

  const ordered = sapXepInstancesTheoBangGoc(withResult);
  customXnActive = ordered.length > 0;
  customXnInstances = ordered.map(inst => ({
    ten_bang: inst.ten_bang,
    ket_qua: sapXepKetQuaTheoBangGoc(inst),
  }));
  currentCustomXnIdx = 0;
  customXnInstances.forEach((inst, idx) => ganNhomKeyChoKetQua(inst.ket_qua, 'cxn'+idx)); // <-- THÊM MỚI

  // Gộp các bảng "mergeOnly" vào bảng gốc tương ứng
  mergeOnly.forEach(inst => {
    const sourceTable = LAB_TABLES.find(t => t.id === inst.source_id);
    const tableId = inst.source_id;
    if (!currentMergeOverrides[tableId]) currentMergeOverrides[tableId] = {};
    if (!currentMergeExtras[tableId]) currentMergeExtras[tableId] = [];
    (inst.ket_qua||[]).forEach(kq => {
      const key  = chuanHoaTen(kq.chi_so);
      const rank = sourceTable ? timRankChiSoTrongBangGoc(sourceTable, kq.chi_so) : null;
      if (rank !== null) {
        currentMergeOverrides[tableId][key] = { don_vi: kq.don_vi, tham_chieu: kq.tham_chieu, gia_tri: kq.gia_tri };
      } else {
        currentMergeExtras[tableId].push({ nhom: kq.nhom || '', chi_so: kq.chi_so, don_vi: kq.don_vi, tham_chieu: kq.tham_chieu });
      }
    });
  });
}

// Bảng riêng: Chỉ số | Kết quả | Đơn vị | Khoảng tham chiếu (không có SI)
function renderCustomXnTableHtml(ketQua){
  let html = '<table class="table table-bordered table-sm"><thead style="background:#f8f9fa"><tr>'
    + '<th style="text-align:center;width:25%">Chỉ số</th><th style="text-align:center;width:15%">Kết quả</th>'
    + '<th style="text-align:center;width:15%">Đơn vị</th><th style="text-align:center;width:35%">Khoảng tham chiếu</th></tr></thead><tbody>';
  let curNhom = null;
  const rowsKeep = (ketQua||[]).filter(kq => !isEmptyVal(kq.gia_tri) || !isEmptyVal(kq.tham_chieu));
  rowsKeep.forEach(kq => {
    if (kq.nhom && kq.nhom !== curNhom) {
      // <-- THÊM MỚI: tiêu đề nhóm có số lượng + có thể đóng/mở
      const grpKey = kq.__grpKey || null;
      const grpCount = kq.__grpCount || 0;
      const isCollapsed = grpKey ? !!labGroupCollapsed[grpKey] : false;
      html += '<tr><td colspan="4" style="background:#f5f5f5">'
        + (grpKey
            ? `<div class="lab-group-header${isCollapsed?' collapsed':''}" data-grp="${grpKey}" onclick="toggleLabGroup('${grpKey}')">`
              + '<i class="bi bi-chevron-down lab-group-toggle"></i>'
              + `<b>${esc(kq.nhom)}</b><span class="lab-group-count">(${grpCount})</span></div>`
            : `<b>${esc(kq.nhom)}</b>`)
        + '</td></tr>';
      curNhom = kq.nhom;
    } else if (!kq.nhom) { curNhom = null; }

    // <-- THÊM MỚI: gắn class/data-grp để có thể ẩn/hiện theo nhóm
    const rowGrpKey = kq.__grpKey || null;
    const rowHidden = rowGrpKey ? !!labGroupCollapsed[rowGrpKey] : false;
    const rowAttrs  = rowGrpKey ? ` class="lab-group-row${rowHidden?' hidden':''}" data-grp="${rowGrpKey}"` : '';

    html += '<tr' + rowAttrs + '><td' + (kq.nhom ? ' style="padding-left:24px"' : '') + '>' + formatChem(kq.chi_so) + '</td>'
          + '<td style="text-align:center">' + formatChem(kq.gia_tri) + '</td>'
          + '<td style="text-align:center">' + formatChem(kq.don_vi) + '</td>'
          + '<td style="text-align:center">' + formatChem(kq.tham_chieu) + '</td></tr>';
  });
  html += '</tbody></table>';
  return html;
}

function renderCustomXnBody(idx){
  currentCustomXnIdx = idx;
  const inst = customXnInstances[idx];
  document.getElementById('labBody').innerHTML = inst ? renderCustomXnTableHtml(inst.ket_qua) : '<div class="lab-empty">Không có dữ liệu.</div>';
}

function switchCustomXnTab(btn, idx){
  document.querySelectorAll('#labTabs button').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  renderCustomXnBody(idx);
}

// Gọi mỗi khi chuyển câu: nếu câu có xetnghiem riêng -> hiện đúng bảng đó; ngược lại -> hiện bình thường
function renderLabPanelForCurrentQuestion(q){
  chuanBiCustomXn(q);
  const tabsWrap = document.getElementById('labTabs');
  const siLabel  = labSIToggle ? labSIToggle.closest('label') : null;
  if (labSearchInput) labSearchInput.value = '';

  if (customXnActive) {
    // Có ít nhất 1 bảng xét nghiệm riêng ĐÃ có kết quả -> giữ nguyên hành vi cũ
    if (siLabel) siLabel.style.display = 'none';
    let tabsHtml = '';
    customXnInstances.forEach((inst, i) => {
      // <-- THÊM MỚI: đếm số chỉ số đang hiển thị trong bảng riêng này
      const cnt = (inst.ket_qua||[]).filter(kq => !isEmptyVal(kq.gia_tri) || !isEmptyVal(kq.tham_chieu)).length;
      tabsHtml += '<button type="button" class="' + (i===0?'active':'') + '" onclick="switchCustomXnTab(this,' + i + ')">'
                + '<span class="lab-tab-name">' + esc(inst.ten_bang) + '</span>'
                + '<span class="lab-count">(' + cnt + ')</span></button>';
    });
    tabsWrap.innerHTML = tabsHtml;
    renderCustomXnBody(0);
  } else {
    // Không có bảng nào có kết quả -> luôn hiện đầy đủ bảng gốc (đã gộp override/extra nếu có)
    if (siLabel) siLabel.style.display = '';
    if (!LAB_TABLES.length) {
      tabsWrap.innerHTML = '<div class="lab-empty" style="padding:10px 16px">Chưa có bảng xét nghiệm nào.</div>';
      document.getElementById('labBody').innerHTML = '<div class="lab-empty">Dữ liệu bảng tham chiếu sẽ được cập nhật sau.</div>';
      return;
    }
    let tabsHtml = '';
    LAB_TABLES.forEach((lt, i) => {
      const dataVoiExtra = layDataVoiExtraChoBang(lt.id);
      const overrideMap  = currentMergeOverrides[lt.id] || {};
      const cnt = demTongSoChiSoBang(dataVoiExtra, overrideMap);
      tabsHtml += '<button type="button" class="' + (i===0?'active':'') + '" data-tab-id="' + lt.id + '" onclick="switchLabTab(this)">'
                + '<span class="lab-tab-name">' + esc(lt.ten_bang) + '</span>'
                + '<span class="lab-count">(' + cnt + ')</span></button>';
    });
    tabsWrap.innerHTML = tabsHtml;
    renderLabBody(LAB_TABLES[0].id);
  }
}

let siMode = false; // <-- THÊM

// <-- THÊM
function siValOrFallback(siArr, normalArr, i){
  const v = siArr && siArr[i];
  return (v !== undefined && v !== '') ? v : (normalArr ? (normalArr[i] || '') : '');
}

function renderLabTableHtml(groups, overrideMap){
  overrideMap = overrideMap || {};
  let html = '<table class="table table-bordered table-sm"><thead style="background: #f8f9fa"><tr>'
    + '<th style="text-align:center">Tên chỉ số</th>'
    + '<th style="width:20%;text-align:center">Đơn vị' + (siMode?'':'') + '</th>'
    + '<th style="text-align:center;width:40%">Khoảng tham chiếu' + (siMode?'':'') + '</th>'
    + '</tr></thead><tbody>';

  groups.forEach(g=>{
    if (g.is_single) {
      const key = chuanHoaTen(g.ten_nhom);
      const { entry: en0, hasResult } = apDungOverrideChoEntry(g.entries[0] || {}, key, overrideMap);
      if (!entryCoNenHienThi(en0, hasResult)) return; // ẩn cả chỉ số này

      const refs   = (en0.refs && en0.refs.length) ? en0.refs : [''];
      const units  = (en0.units && en0.units.length) ? en0.units : [''];
      const siRefs = en0.si_refs || [];
      const siUnits= en0.si_units || [];
      refs.forEach((r, i)=>{
        const unitVal = siMode ? siValOrFallback(siUnits, units, i) : (units[i]||'');
        const refVal  = siMode ? siValOrFallback(siRefs,  refs,  i) : r;
        html += '<tr>';
        if (i===0) html += `<td rowspan="${refs.length}"><b>${formatChem(g.ten_nhom)}</b></td>`;
        html += `<td>${formatChem(unitVal)}</td><td>${formatChem(refVal)}</td></tr>`;
      });
    } else {
      // Lọc entry con: bỏ chỉ số không có tham chiếu (và không có kết quả riêng)
      const keptEntries = [];
      (g.entries||[]).forEach(en=>{
        const key = chuanHoaTen((en.values && en.values[0]) || '');
        const { entry: enOv, hasResult } = apDungOverrideChoEntry(en, key, overrideMap);
        if (entryCoNenHienThi(enOv, hasResult)) keptEntries.push(enOv);
      });
      if (!keptEntries.length) return; // ẩn cả nhóm + tiêu đề nhóm

      // <-- THÊM MỚI: key nhóm để đóng/mở + số lượng (ưu tiên số đã tính sẵn trên dữ liệu đầy đủ, không đổi khi tìm kiếm)
      const grpKey      = g.__grpKey || null;
      const grpCount     = (g.__count !== undefined) ? g.__count : keptEntries.length;
      const isCollapsed = grpKey ? !!labGroupCollapsed[grpKey] : false;

      if (g.ten_nhom) {
        html += '<tr><td colspan="3" style="background:#f5f5f5">'
          + (grpKey
              ? `<div class="lab-group-header${isCollapsed?' collapsed':''}" data-grp="${grpKey}" onclick="toggleLabGroup('${grpKey}')">`
                + '<i class="bi bi-chevron-down lab-group-toggle"></i>'
                + `<b>${formatChem(g.ten_nhom)}</b><span class="lab-group-count">(${grpCount})</span></div>`
              : `<b>${formatChem(g.ten_nhom)}</b><span class="lab-group-count">(${grpCount})</span>`)
          + '</td></tr>';
      }
      const indentStyle = g.ten_nhom ? ' style="padding-left:24px"' : '';
      const rowAttrs = (g.ten_nhom && grpKey)
          ? ` class="lab-group-row${isCollapsed?' hidden':''}" data-grp="${grpKey}"`
          : '';

      keptEntries.forEach(en=>{
        const vals   = en.values && en.values.length ? en.values : [''];
        const refs   = en.refs && en.refs.length ? en.refs : [''];
        const units  = en.units && en.units.length ? en.units : [''];
        const siRefs = en.si_refs  || [];
        const siUnits= en.si_units || [];
        const rows = Math.max(vals.length, refs.length);
        for (let i=0;i<rows;i++){
          const unitVal = siMode ? siValOrFallback(siUnits, units, i) : (units[i]||'');
          const refVal  = siMode ? siValOrFallback(siRefs,  refs,  i) : (refs[i]||'');
          html += '<tr style="vertical-align: middle;"' + rowAttrs + '>';   // <-- THÊM rowAttrs
          if (vals.length === 1) { if (i===0) html += `<td rowspan="${rows}"${indentStyle}>${formatChem(vals[0])}</td>`; }
          else html += `<td${indentStyle}>${formatChem(vals[i]||'')}</td>`;
          if (refs.length === 1) {
            if (i===0) {
              const u0 = siMode ? siValOrFallback(siUnits, units, 0) : (units[0]||'');
              const r0 = siMode ? siValOrFallback(siRefs,  refs,  0) : (refs[0]||'');
              html += `<td style="text-align:center" rowspan="${rows}">${formatChem(u0)}</td><td style="text-align:center" rowspan="${rows}">${formatChem(r0)}</td>`;
            }
          } else {
            html += `<td style="text-align:center">${formatChem(unitVal)}</td><td style="text-align:center">${formatChem(refVal)}</td>`;
          }
          html += '</tr>';
        }
      });
    }
  });

  html += '</tbody></table>';
  return html;
}

// BỔ SUNG: Hàm lọc dữ liệu thông minh theo nhóm nhỏ hoặc tên chỉ số
function filterLabData(data, query) {
  if (!query) return data;
  const q = query.toLowerCase();
  const filtered = [];

  data.forEach(g => {
    const groupMatch = (g.ten_nhom || '').toLowerCase().includes(q);

    if (g.is_single) {
      if (groupMatch) filtered.push(g);
    } else {
      if (groupMatch) {
        // Nếu khớp tên nhóm nhỏ -> Giữ nguyên nhóm và tất cả chỉ số trong nhóm
        filtered.push(g);
      } else {
        // Nếu không khớp tên nhóm -> Lọc tìm các chỉ số khớp trong nhóm đó
        const matchedEntries = (g.entries || []).filter(en => {
          return (en.values || []).some(v => String(v).toLowerCase().includes(q));
        });

        if (matchedEntries.length > 0) {
          // Tạo nhóm chứa tên nhóm cũ nhưng chỉ giữ lại các chỉ số khớp
          filtered.push({
            ...g,
            entries: matchedEntries
          });
        }
      }
    }
  });

  return filtered;
}

function danhDauTabCoKetQuaTimKiem(kw){
  const kwLower = kw.toLowerCase();
  if (customXnActive) {
    document.querySelectorAll('#labTabs button').forEach((btn, i) => {
      const inst = customXnInstances[i];
      const match = !!kw && inst && inst.ket_qua.some(kq =>
        (kq.chi_so||'').toLowerCase().includes(kwLower) ||
        (kq.nhom||'').toLowerCase().includes(kwLower)
      );
      btn.classList.toggle('has-match', match);
    });
  } else {
    document.querySelectorAll('#labTabs button[data-tab-id]').forEach(btn => {
      const tableId = parseInt(btn.dataset.tabId, 10);
      const dataVoiExtra = layDataVoiExtraChoBang(tableId);   // <-- THAY cho đoạn build thủ công cũ
      const match = !!kw && filterLabData(dataVoiExtra, kw).length > 0;
      btn.classList.toggle('has-match', match);
    });
  }
}

function renderLabBody(tableId, query = ''){
  currentLabTableId = tableId;
  const body = document.getElementById('labBody');
  const t = LAB_TABLES.find(x => x.id === tableId);

  let noteHtml = '';
  if (t && t.ghi_chu && t.ghi_chu.trim() !== '') {
    noteHtml = '<div class="lab-note">' + esc(t.ghi_chu).replace(/\n/g, '<br>') + '</div>';
  }

  if (!t || !t.data || !t.data.length) {
    body.innerHTML = noteHtml + '<div class="lab-empty">Chưa có dữ liệu cho bảng này.</div>';
    return;
  }

  const overrideMap = currentMergeOverrides[tableId] || {};   // <-- CHUYỂN LÊN TRƯỚC (trước đây khai báo phía dưới)
  let dataVoiExtra = layDataVoiExtraChoBang(tableId);          // <-- THAY cho đoạn nối extras cũ

  // <-- THÊM MỚI: gắn key nhóm + số lượng (tính trên dữ liệu ĐẦY ĐỦ, chưa lọc theo tìm kiếm)
  dataVoiExtra.forEach((g, gIdx) => {
    g.__grpKey = 'tbl' + tableId + '_g' + gIdx;
    if (!g.is_single) g.__count = demSoChiSoNhom(g.entries, overrideMap);
  });

  const filteredData = filterLabData(dataVoiExtra, query);
  if (!filteredData.length) {
    body.innerHTML = noteHtml + '<div class="lab-empty">Không tìm thấy chỉ số hoặc nhóm phù hợp.</div>';
    return;
  }

  const rendered = renderLabTableHtml(filteredData, overrideMap);
  body.innerHTML = noteHtml + (rendered.includes('<tr')
    ? rendered
    : '<div class="lab-empty">Không có chỉ số nào có khoảng tham chiếu.</div>');
}

function switchLabTab(btn){
  document.querySelectorAll('#labTabs button').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  const q = labSearchInput ? labSearchInput.value.trim() : '';
  renderLabBody(parseInt(btn.dataset.tabId, 10), q);
}

const labSearchInput = document.getElementById('labSearch');
if (labSearchInput) {
  labSearchInput.addEventListener('input', () => {
    const kw = labSearchInput.value.trim();
    if (customXnActive) {
      const inst = customXnInstances[currentCustomXnIdx];
      if (!inst) return;
      const filtered = !kw ? inst.ket_qua : inst.ket_qua.filter(kq =>
        (kq.chi_so||'').toLowerCase().includes(kw.toLowerCase()) ||
        (kq.nhom||'').toLowerCase().includes(kw.toLowerCase())
      );
      document.getElementById('labBody').innerHTML = renderCustomXnTableHtml(filtered);
    } else {
      renderLabBody(currentLabTableId, kw);
    }
    danhDauTabCoKetQuaTimKiem(kw);   // <-- THÊM
  });
}

// <-- THÊM: bật/tắt chế độ SI
const labSIToggle = document.getElementById('labSIToggle');
if (labSIToggle) {
  labSIToggle.addEventListener('change', () => {
    siMode = labSIToggle.checked;
    const q = labSearchInput ? labSearchInput.value.trim() : '';
    renderLabBody(currentLabTableId, q);
  });
}

if (LAB_TABLES.length) renderLabBody(LAB_TABLES[0].id);

/* ==== Bôi đen (highlight) nội dung đề bài — chỉ highlight đúng phần đã chọn ==== */
function applyHighlightFromSelection(){
  const sel = window.getSelection();
  if (!sel || sel.isCollapsed || sel.rangeCount === 0) return;
  const range = sel.getRangeAt(0);
  const vignetteEl = document.querySelector('#qBody .q-vignette');
  if (!vignetteEl || !vignetteEl.contains(range.commonAncestorContainer)) return;

  let node = range.commonAncestorContainer;
  if (node.nodeType === 3) node = node.parentElement;
  const existingMark = node.closest && node.closest('mark.user-hl');

  if (existingMark) {
    // Chọn lại vùng đã bôi đen -> bỏ highlight
    const parent = existingMark.parentNode;
    while (existingMark.firstChild) parent.insertBefore(existingMark.firstChild, existingMark);
    parent.removeChild(existingMark);
  } else {
    const mark = document.createElement('mark');
    mark.className = 'user-hl';
    try { range.surroundContents(mark); } catch (e) { /* vùng chọn cắt qua nhiều thẻ - bỏ qua */ }
  }
  sel.removeAllRanges();
  vignetteHighlights[curIdx] = vignetteEl.innerHTML;
}
document.getElementById('qBody').addEventListener('mouseup', applyHighlightFromSelection);
// <-- THÊM MỚI: hỗ trợ bôi đen bằng cách chạm giữ + chọn văn bản trên di động
document.getElementById('qBody').addEventListener('touchend', applyHighlightFromSelection);

/* ==== Thông tin thí sinh (popover kiểu icon người) ==== */
const infoBtn = document.getElementById('btnInfo');
const infoPopover = document.getElementById('infoPopover');
infoBtn.onclick = (e) => {
  e.stopPropagation();
  infoPopover.classList.toggle('show');
};
document.addEventListener('click', (e) => {
  if (!infoPopover.contains(e.target) && e.target !== infoBtn && !infoBtn.contains(e.target)) {
    infoPopover.classList.remove('show');
  }
});

/* ==== THÊM MỚI: Mở/đóng menu "Tiến trình" dạng drawer trên di động ==== */
const sidebarLeftEl = document.getElementById('sidebarLeft');
const sidebarBackdropEl = document.getElementById('sidebarBackdrop');
const btnDrawerEl = document.getElementById('btnDrawer');
const sidebarCloseBtnEl = document.getElementById('sidebarCloseBtn');

function openDrawer(){
  sidebarLeftEl.classList.add('open');
  sidebarBackdropEl.classList.add('show');
}
function closeDrawer(){
  sidebarLeftEl.classList.remove('open');
  sidebarBackdropEl.classList.remove('show');
}
if (btnDrawerEl) {
  btnDrawerEl.onclick = () => {
    sidebarLeftEl.classList.contains('open') ? closeDrawer() : openDrawer();
  };
}
if (sidebarBackdropEl) sidebarBackdropEl.onclick = closeDrawer;
if (sidebarCloseBtnEl) sidebarCloseBtnEl.onclick = closeDrawer;

render();
</script>

<?php elseif ($viewResult): ?>
<!-- ================= KẾT QUẢ ================= -->
<div class="result-wrapper" style="max-width:900px; margin:30px auto; padding:0 16px; min-height:100vh;">
  <div class="result-summary">
    <div class="result-score"><?= number_format((float)$viewResult['score'], 2) ?><small style="font-size:16px;color:#888">/10</small></div>
    <div><b><?= (int)$viewResult['so_cau_dung'] ?></b>/<?= (int)$viewResult['total_cau'] ?> câu đúng</div>
    <div>
      
      <div class="small text-muted">Bắt đầu: <?= e(date('H:i:s d/m/Y', strtotime($viewResult['start_time']))) ?> — Nộp bài: <?= e(date('H:i:s d/m/Y', strtotime($viewResult['submitted_at']))) ?></div>
      <div class="small text-muted">Số báo danh: <?= e($viewResult['sbd']) ?> — Đề thi: <?= e($viewResult['ten_matran']) ?> (<?= e($viewResult['ma_de']) ?>)</div>
      <a href="?action=logout" class="btn btn-light btn-sm"><i class="bi bi-box-arrow-right"></i> Làm bài khác</a>
    </div>
  </div>
  <div id="resultList"></div>
</div>

<style>
/* ==== THÊM MỚI: responsive cho trang kết quả trên di động ==== */
@media (max-width:768px){
  .result-wrapper{ padding:0 12px !important; margin:14px auto !important; }
  .result-summary{ flex-direction:column; align-items:flex-start; gap:10px; padding:16px !important; }
  .result-score{ font-size:30px !important; }
  .result-card{ padding:14px; }
  .choice-box{ padding:9px 12px; }
}
</style>

<script>
function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function fixNewlines(str) {
  const s = (str || '').replace(/\\n/g, '\n').replace(/\r\n/g, '\n');
  const lines = s.split('\n');
  const isTableLine = l => l.includes('|') && l.trim() !== '';
  let out = '';
  for (let i = 0; i < lines.length; i++) {
    out += lines[i];
    if (i < lines.length - 1) {
      out += (isTableLine(lines[i]) && isTableLine(lines[i+1])) ? '\n' : '\n\n';
    }
  }
  return out;
}

function chuanHoaGroupCase(q) {
  if (!q || q.kind !== 'group' || !q.parts || q.parts.length < 2) return q;
  if (q._chuanHoaDone) return q;
  q._chuanHoaDone = true;

  const getParas = (text) => {
    const raw = (text || '').replace(/\\n/g, '\n').replace(/\r\n/g, '\n');
    const rawLines = raw.split('\n');
    const isTableLine = l => l.includes('|') && l.trim() !== '';
    const paras = [];
    let i = 0;
    while (i < rawLines.length) {
      const line = rawLines[i];
      if (line.trim() === '') { i++; continue; }
      if (isTableLine(line)) {
        const block = [line];
        let j = i + 1;
        while (j < rawLines.length && isTableLine(rawLines[j])) { block.push(rawLines[j]); j++; }
        paras.push(block.join('\n'));
        i = j;
      } else {
        paras.push(line.trim());
        i++;
      }
    }
    return paras;
  };

  const p0Paras = getParas(q.parts[0].lead_in);
  const p1Paras = getParas(q.parts[1].lead_in);
  if (p0Paras.length === 0) return q;

  // TH1: Các ý bị lặp lại đoạn văn mở đầu
  let commonParas = [];
  for (let k = 0; k < p0Paras.length - 1 && k < p1Paras.length - 1; k++) {
    if (p0Paras[k] === p1Paras[k]) commonParas.push(p0Paras[k]);
    else break;
  }

  if (commonParas.length > 0) {
    if (!q.group_stem) q.group_stem = commonParas.join('\n\n');
    q.parts.forEach(p => {
      const paras = getParas(p.lead_in);
      let matchCount = 0;
      for (let k = 0; k < commonParas.length; k++) {
        if (paras[k] === commonParas[k]) matchCount++;
        else break;
      }
      if (matchCount > 0) p.lead_in = paras.slice(matchCount).join('\n\n');
    });
    return q;
  }

  // TH2: Ý 2.1 chứa bệnh án gốc, ý 2.2 là diễn tiến tiếp theo
  if (p0Paras.length >= 2) {
    if (!q.group_stem) q.group_stem = p0Paras.slice(0, -1).join('\n\n');
    q.parts[0].lead_in = p0Paras[p0Paras.length - 1];
  }
  return q;
}

function hideReferenceRangeColumns(container){
  if (!container) return;
  container.querySelectorAll('table').forEach(table => {
    const headerRow = table.querySelector('tr');
    if (!headerRow) return;
    Array.from(headerRow.children).forEach((cell, colIndex) => {
      if (cell.textContent.trim().toLowerCase().includes('khoảng tham chiếu')) {
        table.querySelectorAll('tr').forEach(row => {
          if (row.children[colIndex]) row.children[colIndex].style.display = 'none';
        });
      }
    });
  });
}

const RESULT_QUESTIONS = <?= json_encode($resultQuestionsJs, JSON_UNESCAPED_UNICODE) ?>;
const USER_ANSWERS = <?= json_encode($savedAnswers, JSON_UNESCAPED_UNICODE) ?>;
const LAB_TABLES = <?= json_encode($labTablesJs, JSON_UNESCAPED_UNICODE) ?>;

function formatChem(s){
  let t = esc(s);
  t = t.replace(/\^(\+|-|\d+)/g, '<sup>$1</sup>');
  t = t.replace(/_(\d+)/g, '<sub>$1</sub>');
  return t;
}
// ==== THÊM MỚI: xác định đúng/sai + render danh sách phương án cho trang KẾT QUẢ, theo đúng loại câu hỏi ====
function chamVaRenderKetQua(loaiCau, choices, userAnswer){
  if (loaiCau === 'nhieu_dap_an') {
    const correctSet = (choices||[]).filter(c=>c.is_correct).map(c=>c.option_id).sort();
    const userSet = Array.isArray(userAnswer) ? userAnswer.slice().sort() : [];
    const dung = correctSet.length > 0 && JSON.stringify(correctSet) === JSON.stringify(userSet);
    let html = '';
    (choices||[]).forEach(c=>{
      const selected = Array.isArray(userAnswer) && userAnswer.includes(c.option_id);
      let cls = '';
      if (c.is_correct) cls = 'correct';
      else if (selected) cls = 'wrong-selected';
      html += '<div class="choice-box ' + cls + '"><b>' + esc(c.option_id) + '.</b> ' + esc(c.text)
            + (selected ? ' <span class="badge bg-secondary">Bạn chọn</span>' : '')
            + (c.is_correct ? ' <span class="badge bg-success">Đáp án đúng</span>' : '') + '</div>';
    });
    return { dung, html };
  }
  if (loaiCau === 'dung_sai') {
    const map = (userAnswer && typeof userAnswer === 'object' && !Array.isArray(userAnswer)) ? userAnswer : {};
    let dung = (choices||[]).length > 0;
    let html = '';
    (choices||[]).forEach(c=>{
      const userVal = map[c.option_id] || null;
      const correctVal = c.is_correct ? 'dung' : 'sai';
      const dungY = userVal === correctVal;
      if (!dungY) dung = false;
      html += '<div class="choice-box ' + (dungY?'correct':'wrong-selected') + '"><b>' + esc(String(c.option_id).toLowerCase()) + ')</b> ' + esc(c.text)
            + ' <span class="' + (dungY?'tag-correct':'tag-wrong') + '">Bạn chọn: '
            + (userVal === 'dung' ? 'Đúng' : (userVal === 'sai' ? 'Sai' : '(bỏ trống)')) + '</span>'
            + ' <span class="badge ' + (dungY?'bg-success':'bg-danger') + '">Đáp án: ' + (c.is_correct?'Đúng':'Sai') + '</span></div>';
    });
    return { dung, html };
  }
  // mot_dap_an
  const correctObj = (choices||[]).find(c=>c.is_correct);
  const correctId = correctObj ? correctObj.option_id : null;
  const dung = userAnswer === correctId;
  let html = '';
  (choices||[]).forEach(c=>{
    let cls = '';
    if (c.is_correct) cls = 'correct';
    else if (c.option_id === userAnswer) cls = 'wrong-selected';
    html += '<div class="choice-box ' + cls + '"><b>' + esc(c.option_id) + '.</b> ' + esc(c.text)
          + (c.option_id === userAnswer ? ' <span class="badge bg-secondary">Bạn chọn</span>' : '')
          + (c.is_correct ? ' <span class="badge bg-success">Đáp án đúng</span>' : '') + '</div>';
  });
  return { dung, html };
}
function isEmptyVal(v){ return v === undefined || v === null || String(v).trim() === ''; }

function timRankChiSoTrongBangGocRS(sourceTable, chiSo){
  if (!sourceTable || !sourceTable.data) return null;
  let rank = 0;
  for (const g of sourceTable.data) {
    if (g.is_single) {
      if ((g.ten_nhom||'').trim().toLowerCase() === (chiSo||'').trim().toLowerCase()) return rank;
      rank++;
    } else {
      for (const en of (g.entries||[])) {
        const vals = (en.values && en.values.length) ? en.values : [];
        if (vals.some(v => (v||'').trim().toLowerCase() === (chiSo||'').trim().toLowerCase())) return rank;
        rank++;
      }
    }
  }
  return null;
}

function sapXepKetQuaRS(instance){
  const sourceTable = instance.source_id ? LAB_TABLES.find(t => t.id === instance.source_id) : null;
  const list = (instance.ket_qua || []).map((kq, i) => ({
    kq, idx: i,
    rank: sourceTable ? timRankChiSoTrongBangGocRS(sourceTable, kq.chi_so) : null,
  }));
  list.sort((a,b) => {
    const ar = (a.rank === null) ? Infinity : a.rank;
    const br = (b.rank === null) ? Infinity : b.rank;
    return (ar !== br) ? (ar - br) : (a.idx - b.idx);
  });
  return list.map(x => x.kq);
}

function sapXepInstancesRS(instances){
  const withRank = instances.map((inst, i) => {
    let rank = null;
    if (inst.source_id) {
      const pos = LAB_TABLES.findIndex(t => t.id === inst.source_id);
      if (pos !== -1) rank = pos;
    }
    return { inst, idx: i, rank };
  });
  withRank.sort((a,b) => {
    const ar = (a.rank === null) ? Infinity : a.rank;
    const br = (b.rank === null) ? Infinity : b.rank;
    return (ar !== br) ? (ar - br) : (a.idx - b.idx);
  });
  return withRank.map(x => x.inst);
}

function renderXnResultsHtmlView(instances){
  if (!instances || !instances.length) return '';
  const ordered = sapXepInstancesRS(instances);
  let html = '';
  ordered.forEach(inst => {
    const ketQuaAll = sapXepKetQuaRS(inst);
    const ketQua = ketQuaAll.filter(kq => !isEmptyVal(kq.gia_tri) || !isEmptyVal(kq.tham_chieu));
    if (!ketQua.length) return;

    html += '<div class="mb-3"><div class="fw-bold mb-1" style="color:var(--primary)">' + esc(inst.ten_bang) + '</div>';
    html += '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">';
    html += '<table class="table table-bordered table-sm" style="min-width:460px"><thead style="background:#f8f9fa"><tr>'
          + '<th style="text-align:center">Chỉ số</th><th style="text-align:center">Kết quả</th>'
          + '<th style="text-align:center">Đơn vị</th><th style="text-align:center;">Khoảng tham chiếu</th></tr></thead><tbody>';
    let curNhom = null;
    ketQua.forEach(kq => {
      if (kq.nhom && kq.nhom !== curNhom) {
        html += '<tr><td colspan="4" style="background:#f5f5f5"><b>' + esc(kq.nhom) + '</b></td></tr>';
        curNhom = kq.nhom;
      } else if (!kq.nhom) { curNhom = null; }
      html += '<tr><td' + (kq.nhom ? ' style="padding-left:24px"' : '') + '>' + formatChem(kq.chi_so) + '</td>'
            + '<td style="text-align:center">' + formatChem(kq.gia_tri) + '</td>'
            + '<td style="text-align:center">' + formatChem(kq.don_vi) + '</td>'
            + '<td style="text-align:center">' + formatChem(kq.tham_chieu) + '</td></tr>';
    });
    html += '</tbody></table></div></div>';
  });
  return html;
}

function fmtStopwatch(sec){
  const m = String(Math.floor(sec/60)).padStart(2,'0');
  const s = String(sec%60).padStart(2,'0');
  return m+':'+s;
}

function renderAusculSectionResult(q, qi){
  if (!q.ausculta || !q.ausculta.image || !q.ausculta.image.duong_dan) return '';
  const img = q.ausculta.image;
  const points = q.ausculta.points || [];
  let dotsHtml = '';
  points.forEach((p, idx)=>{
    dotsHtml += '<div class="auscul-marker-dot" data-idx="'+idx+'" style="left:'+p.x+'%;top:'+p.y+'%" title="'+esc(p.ten_vi_tri||('Vị trí '+(idx+1)))+'"></div>';
  });
  return '<div class="auscul-side-col" id="ausculWrap_res_'+qi+'">'
    + '<div class="auscul-image-box">'
    +   '<div class="auscul-img-wrap"><img src="'+esc(img.duong_dan)+'"><div class="auscul-markers">'+dotsHtml+'</div></div>'
    + '</div>'
    + '<div class="auscul-right-col">'
    +   '<div class="auscul-function-box">'
    +     '<div class="auscul-func-header"><span class="auscul-point-name">-- Chọn vị trí nghe --</span></div>'
    +     '<div class="auscul-func-body"><div class="text-muted small">Bấm vào 1 chấm trên hình để nghe.</div></div>'
    +   '</div>'
    + '</div>'
    + '</div>';
}

function bindAusculEventsResult(q, qi){
  const wrap = document.getElementById('ausculWrap_res_'+qi);
  if (!wrap) return;
  wrap.querySelectorAll('.auscul-marker-dot').forEach(dot=>{
    dot.onclick = () => chonViTriNgheResult(wrap, q, parseInt(dot.dataset.idx, 10));
  });
}

function chonViTriNgheResult(wrap, q, idx){
  wrap.querySelectorAll('.auscul-marker-dot').forEach(d=>{
    d.classList.toggle('active', parseInt(d.dataset.idx,10) === idx);
  });
  const p = q.ausculta.points[idx];
  wrap.querySelector('.auscul-point-name').textContent = p.ten_vi_tri || ('Vị trí ' + (idx+1));

  const body = wrap.querySelector('.auscul-func-body');
  const options = [];
  if (p.loai === 'chuong' || p.loai === 'ca_hai') options.push(['chuong','Mặt chuông']);
  if (p.loai === 'mang'   || p.loai === 'ca_hai') options.push(['mang','Mặt màng']);

  if (!options.length) {
    body.innerHTML = '<div class="text-muted small">Vị trí này chưa có dữ liệu âm thanh.</div>';
    return;
  }
  let selHtml = '<select class="auscul-mat-select">';
  options.forEach(([val,label])=> selHtml += '<option value="'+val+'">'+label+'</option>');
  selHtml += '</select>';

  body.innerHTML =
      '<div class="auscul-player">'
    +   selHtml
    +   '<button type="button" class="auscul-play-btn"><i class="bi bi-play-fill"></i></button>'
    +   '<div class="auscul-vol-wrap">'
    +     '<button type="button" class="auscul-vol-btn" title="Âm lượng"><i class="bi bi-volume-up-fill"></i></button>'
    +     '<div class="auscul-vol-popup"><input type="range" min="0" max="100" value="100" class="auscul-vol-slider"></div>'
    +   '</div>'
    +   '<input type="range" class="auscul-seek" min="0" max="100" value="0">'
    +   '<span class="auscul-time">00:00 / 00:00</span>'
    +   '<audio class="auscul-audio-el" preload="metadata"></audio>'
    + '</div>';

  napAmThanhTheoMatResult(wrap, p, options[0][0]);
  body.querySelector('select').onchange = (e) => napAmThanhTheoMatResult(wrap, p, e.target.value);
}

function napAmThanhTheoMatResult(wrap, p, mat){
  const audio = wrap.querySelector('.auscul-audio-el');
  const src = mat === 'chuong' ? p.audio_chuong : p.audio_mang;
  audio.pause();
  audio.src = src || '';
  wrap.querySelector('.auscul-play-btn').innerHTML = '<i class="bi bi-play-fill"></i>';
  wrap.querySelector('.auscul-seek').value = 0;
  wrap.querySelector('.auscul-time').textContent = '00:00 / 00:00';
  bindAusculAudioPlayerResult(wrap);
}

function bindAusculAudioPlayerResult(wrap){
  const audio   = wrap.querySelector('.auscul-audio-el');
  const playBtn = wrap.querySelector('.auscul-play-btn');
  const seek    = wrap.querySelector('.auscul-seek');
  const timeEl  = wrap.querySelector('.auscul-time');
  const volBtn    = wrap.querySelector('.auscul-vol-btn');
  const volPopup  = wrap.querySelector('.auscul-vol-popup');
  const volSlider = wrap.querySelector('.auscul-vol-slider');

  audio.volume = (parseInt(volSlider.value, 10) || 100) / 100;

  playBtn.onclick = () => {
    if (!audio.src) return;
    if (audio.paused) { audio.play(); playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>'; }
    else { audio.pause(); playBtn.innerHTML = '<i class="bi bi-play-fill"></i>'; }
  };
  audio.onended = () => { playBtn.innerHTML = '<i class="bi bi-play-fill"></i>'; };
  audio.onloadedmetadata = () => {
    seek.max = Math.floor(audio.duration) || 0;
    timeEl.textContent = '00:00 / ' + fmtStopwatch(Math.floor(audio.duration)||0);
  };
  audio.ontimeupdate = () => {
    seek.value = Math.floor(audio.currentTime);
    timeEl.textContent = fmtStopwatch(Math.floor(audio.currentTime)) + ' / ' + fmtStopwatch(Math.floor(audio.duration)||0);
  };
  seek.oninput = () => { audio.currentTime = seek.value; };

  volBtn.onclick = (e) => {
    e.stopPropagation();
    volPopup.classList.toggle('show');
  };
  volSlider.oninput = () => {
    const v = parseInt(volSlider.value, 10);
    audio.volume = v / 100;
    volBtn.innerHTML = v == 0
      ? '<i class="bi bi-volume-mute-fill"></i>'
      : (v < 50 ? '<i class="bi bi-volume-down-fill"></i>' : '<i class="bi bi-volume-up-fill"></i>');
  };
}

document.addEventListener('click', (e) => {
  document.querySelectorAll('.auscul-vol-popup.show').forEach(p=>{
    if (!p.parentElement.contains(e.target)) p.classList.remove('show');
  });
});

// ==== THÊM MỚI: tìm ảnh dùng chung giữa các ý trong 1 case study ====
function layAnhChungNhomRS(q){
  if (!q || !q.parts || !q.parts.length) return null;
  const counts = {};
  q.parts.forEach(p => { if (p.image_path) counts[p.image_path] = (counts[p.image_path]||0) + 1; });
  let best = null, bestCount = 1;
  Object.keys(counts).forEach(k => { if (counts[k] > bestCount) { best = k; bestCount = counts[k]; } });
  return best;
}

// Render Card kết quả cho câu Case Study
function renderGroupResultCard(q, i){
  chuanHoaGroupCase(q);
  const commonImg = layAnhChungNhomRS(q); // <-- THÊM MỚI

  let h = '<div class="result-card">';
  h += '<div class="d-flex justify-content-between mb-2"><b style="color:#1a237e">Câu ' + (i+1) + ' (Case Study - ' + (q.parts ? q.parts.length : 0) + ' câu)</b></div>';
  if (q.group_stem) h += '<div class="q-vignette">' + marked.parse(fixNewlines(q.group_stem)) + '</div>';

  // <-- THÊM MỚI: hiện ảnh dùng chung 1 lần ngay dưới đề bài chung
  if (commonImg) {
    h += '<div style="text-align:center;margin-bottom:14px;">'
       + '<img src="' + esc(commonImg) + '" style="max-width:100%;border-radius:8px;">'
       + '<div class="small text-muted" style="font-style:italic">Hình dùng chung cho các ý hỏi trong câu này</div>'
       + '</div>';
  }

  (q.parts || []).forEach((p, j) => {
    const key = i + '_' + j;
    const loaiCauPart = p.loai_cau || 'mot_dap_an';    // <-- THÊM MỚI
    const userAnswer = USER_ANSWERS[key] || null;

    // <-- THÊM MỚI
    if (loaiCauPart === 'nhieu_dap_an' || loaiCauPart === 'dung_sai') {
      const { dung, html: choicesHtml } = chamVaRenderKetQua(loaiCauPart, p.choices, userAnswer);
      h += '<div style="border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:10px;">';
      h += '<div class="d-flex justify-content-between mb-1" style="color:#1a237e"><b>' + (i+1) + '.' + (j+1) + '</b><span class="' + (dung?'tag-correct':'tag-wrong') + '">'
         + (dung ? '<i class="bi bi-check-circle-fill"></i> Đúng' : '<i class="bi bi-x-circle-fill"></i> Sai') + '</span></div>';
      if (p.image_path && p.image_path !== commonImg) h += '<div style="text-align:center;margin-bottom:8px;"><img src="' + esc(p.image_path) + '" style="max-width:100%;border-radius:6px;"></div>';
      h += '<div class="q-leadin">' + marked.parse(fixNewlines(p.lead_in || '')) + '</div>';
      h += choicesHtml;
      if (p.giai_thich) h += '<div class="small text-muted mt-2 p-2 bg-light rounded"><b>Giải thích:</b> ' + marked.parse(fixNewlines(p.giai_thich)) + '</div>';
      h += '</div>';
      return;
    }
    const userOpt = USER_ANSWERS[key] || null;
    const dung = userOpt === p.correct_option_id;
    h += '<div style="border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:10px;">';
    h += '<div class="d-flex justify-content-between mb-1" style="color:#1a237e"><b>' + (i+1) + '.' + (j+1) + '</b><span class="' + (dung?'tag-correct':'tag-wrong') + '">'
       + (dung ? '<i class="bi bi-check-circle-fill"></i> Đúng' : '<i class="bi bi-x-circle-fill"></i> Sai') + '</span></div>';
    // <-- SỬA: chỉ hiện ảnh riêng nếu KHÁC ảnh dùng chung
    if (p.image_path && p.image_path !== commonImg) {
      h += '<div style="text-align:center;margin-bottom:8px;"><img src="' + esc(p.image_path) + '" style="max-width:100%;border-radius:6px;"></div>';
    }
    h += '<div class="q-leadin">' + marked.parse(fixNewlines(p.lead_in || '')) + '</div>';
    (p.choices||[]).forEach(c=>{
      let cls = '';
      if (c.is_correct) cls = 'correct';
      else if (c.option_id === userOpt) cls = 'wrong-selected';

      h += '<div class="choice-box ' + cls + '"><b>' + esc(c.option_id) + '.</b> ' + esc(c.text)
         + (c.option_id === userOpt ? ' <span class="badge bg-secondary">Bạn chọn</span>' : '')
         + (c.is_correct ? ' <span class="badge bg-success">Đáp án đúng</span>' : '') + '</div>';
    });
    if (p.giai_thich) h += '<div class="small text-muted mt-2 p-2 bg-light rounded"><b>Giải thích:</b> ' + marked.parse(fixNewlines(p.giai_thich)) + '</div>';
    h += '</div>';
  });
  return h + '</div>';
}

let html = '';
RESULT_QUESTIONS.forEach((q, i) => {
  if (!q) { html += '<div class="result-card text-muted">Câu ' + (i+1) + ': (câu hỏi đã bị xoá khỏi ngân hàng)</div>'; return; }
  
  if (q.kind === 'group') {
    html += renderGroupResultCard(q, i);
    return;
  }

  const loaiCau = q.loai_cau || 'mot_dap_an';   // <-- THÊM MỚI

  // <-- THÊM MỚI: câu "Nhiều đáp án đúng" / "Đúng-Sai" dùng cách chấm & hiển thị riêng
  if (loaiCau === 'nhieu_dap_an' || loaiCau === 'dung_sai') {
    const userAnswer = USER_ANSWERS[String(i)] || null;
    const { dung, html: choicesHtml } = chamVaRenderKetQua(loaiCau, q.choices, userAnswer);
    html += '<div class="result-card">';
    html += '<div class="d-flex justify-content-between mb-2"><b style="color:#1a237e">Câu ' + (i+1) + '</b>'
          + '<span class="' + (dung?'tag-correct':'tag-wrong') + '">'
          + (dung ? '<i class="bi bi-check-circle-fill"></i> Đúng' : '<i class="bi bi-x-circle-fill"></i> Sai')
          + '</span></div>';
    if (q.vignette) html += '<div class="q-vignette">' + marked.parse(fixNewlines(q.vignette||'')) + '</div>';
    if (q.image_path) html += '<div style="text-align:center"><img src="' + esc(q.image_path) + '" style="max-width:100%;border-radius:8px;margin-bottom:8px"></div>';
    html += '<div class="q-leadin">' + marked.parse(fixNewlines(q.lead_in || '')) + '</div>';
    html += choicesHtml;
    if (q.giai_thich) html += '<div class="small text-muted mt-2 p-2 bg-light rounded"><b>Giải thích:</b> ' + marked.parse(fixNewlines(q.giai_thich)) + '</div>';
    html += '</div>';
    return;
  }

  const userOpt = USER_ANSWERS[String(i)] || null;
  const dung = userOpt === q.correct_option_id;
  html += '<div class="result-card">';
  html += '<div class="d-flex justify-content-between mb-2"><b style="color: #1a237e">Câu ' + (i+1) + '</b>'
        + '<span class="' + (dung?'tag-correct':'tag-wrong') + '">'
        + (dung ? '<i class="bi bi-check-circle-fill"></i> Đúng' : '<i class="bi bi-x-circle-fill"></i> Sai')
        + '</span></div>';
  if (q.vignette) html += '<div class="q-vignette">' + marked.parse(fixNewlines(q.vignette||'')) + '</div>';
  if (q.image_path) {
    html += '<div style="text-align: center"><img src="' + esc(q.image_path) + '" style="max-width:100%;border-radius:8px;margin-bottom:8px"></div>';
    if (q.image_caption) html += '<div class="small text-muted mb-2" style="font-style:italic;text-align: center">' + esc(q.image_caption) + '</div>';
  }
  html += renderAusculSectionResult(q, i);
  if (q.xetnghiem && q.xetnghiem.length) {
    html += '<h6 class="mt-3">Bảng kết quả xét nghiệm</h6>';
    html += renderXnResultsHtmlView(q.xetnghiem);
  }     
  html += '<div class="q-leadin">' + marked.parse(fixNewlines(q.lead_in || '')) + '</div>';

  if (q.learning_objective) {
    html += '<div class="mb-3 p-2 bg-light border-start border-3 border-primary rounded-end" style="font-size:13.5px;">'
          + esc(q.learning_objective) 
          + '</div>';
  }

  (q.choices||[]).forEach(c=>{
    let cls = '';
    if (c.is_correct) cls = 'correct';
    else if (c.option_id === userOpt) cls = 'wrong-selected';

    html += '<div class="choice-box ' + cls + '"><b>' + esc(c.option_id) + '.</b> ' + esc(c.text)
          + (c.image ? '<div class="mt-2 mb-2"><img src="' + esc(c.image) + '" style="border-radius:6px;max-width:500px;display:block;"></div>' : '')
          + (c.option_id === userOpt ? ' <span class="badge bg-secondary">Bạn chọn</span>' : '')
          + (c.is_correct ? ' <span class="badge bg-success">Đáp án đúng</span>' : '')
          + (c.explanation ? '<div class="small text-muted mt-1">' + marked.parse(fixNewlines(c.explanation)) + '</div>' : '')
          + '</div>';
  });

  if (q.giai_thich) {
    html += '<div class="small text-muted mt-2 p-2 bg-light rounded"><b>Giải thích:</b> ' + marked.parse(fixNewlines(q.giai_thich)) + '</div>';
  }

  html += '</div>';
});

document.getElementById('resultList').innerHTML = html;
RESULT_QUESTIONS.forEach((q, i) => { if (q && q.kind !== 'group') bindAusculEventsResult(q, i); });
hideReferenceRangeColumns(document.getElementById('resultList'));
</script>
<?php endif; ?>
<script>
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("/sw.js")
    .then(() => console.log("SW registered"))
    .catch(err => console.log("SW error", err));
}
</script>
</body>
</html>