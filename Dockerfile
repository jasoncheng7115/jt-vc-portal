FROM php:8.4-apache

# 套用最新 Debian 安全更新（OWASP A02/A03：避免 base image 落後於安全發佈）
# 重 build 時建議加 --pull 以取得最新 base image：docker build --pull -t jaas-auth .
RUN apt-get update \
 && apt-get -y upgrade \
 && apt-get -y --purge autoremove \
 && rm -rf /var/lib/apt/lists/*

# 啟用 rewrite 模組
RUN a2enmod rewrite

# 換 port: 80 -> 58189
RUN sed -i 's/80/58189/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# 允許 .htaccess
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# PHP 安全強化（OWASP A02/A10）：不顯示錯誤、不洩漏 PHP 版本、session 加固、隱藏 Apache 版本
RUN { \
      echo 'display_errors = Off'; \
      echo 'display_startup_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'expose_php = Off'; \
      echo 'session.cookie_httponly = 1'; \
      echo 'session.cookie_samesite = "Lax"'; \
      echo 'session.use_strict_mode = 1'; \
    } > /usr/local/etc/php/conf.d/zz-hardening.ini \
 && { \
      echo 'ServerTokens Prod'; \
      echo 'ServerSignature Off'; \
      echo 'TraceEnable Off'; \
    } > /etc/apache2/conf-available/zz-hardening.conf \
 && a2enconf zz-hardening

# 複製檔案
COPY . /var/www/html/

# 重新命名 .htaccess 並建立必要目錄（資料卷掛載點 /var/jaas-data 用來放 auto-allow.json）
RUN mv /var/www/html/dot.htaccess /var/www/html/.htaccess \
 && mkdir -p /var/jaas-data \
 && chown -R www-data:www-data /var/www/html /var/jaas-data

VOLUME ["/var/jaas-data"]

EXPOSE 58189
