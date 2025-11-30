
# اگر فایل فیزیکی وجود داره و یکی از فرمت‌های تصویریه
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^(.*\.(jpe?g|png|gif))$ /image.php?src=/$1 [L,QSA]
