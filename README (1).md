# Làm bài thi theo ma trận

Ứng dụng PHP một-file (`index.php`) cho thí sinh đăng nhập và làm bài thi được
rút ngẫu nhiên theo ma trận đề.

- **Tên đăng nhập**: Số báo danh (tự do)
- **Mật khẩu**: Mã ma trận (VD: `MT-2026-01`)

## Yêu cầu

- PHP ≥ 7.4 với extension `pdo_sqlite`
- Extension `zip` (`ZipArchive`) — chỉ cần khi chạy ở chế độ bundle (máy thi offline)

## Cấu trúc thư mục

```
index.php
data/            # các file SQLite (không commit — xem .gitignore)
  question_bank.sqlite
  exam_matrix.sqlite
  users.sqlite
  phien_thi.sqlite
  tot_nghiep_bank.sqlite
  lab_values.sqlite
uploads/         # ảnh/audio câu hỏi (không commit)
bundle-dat/      # bundle.dat mã hoá dùng cho máy thi offline (không commit)
bundle_crypto.php  # BẮT BUỘC nếu dùng chế độ bundle — không có trong repo này
```

## Hai chế độ chạy

1. **Máy thường** — không có `bundle-dat/bundle.dat`: app đọc thẳng các file
   SQLite trong `data/`.
2. **Máy thi (kiosk, offline)** — có `bundle-dat/bundle.dat` (hoặc các phần
   `bundle*.dat.part*`): app tự giải mã bằng `bundle_crypto.php`, giải nén zip
   vào thư mục cache cục bộ, rồi dùng dữ liệu trong đó. File `bundle_crypto.php`
   là file phụ trợ riêng, không kèm trong repo — cần đặt cạnh `index.php` khi
   triển khai chế độ này.

## Chạy thử nhanh

```bash
php -S localhost:8000
```

rồi mở `http://localhost:8000/index.php`.

## Lưu ý khi đẩy lên GitHub

`.gitignore` đã loại trừ toàn bộ dữ liệu thật (SQLite, bundle mã hoá, ảnh
upload) — repo chỉ chứa mã nguồn. Trước khi tạo DB thật, có thể tự tạo file
SQLite trống theo đúng tên ở trên trong `data/`.
