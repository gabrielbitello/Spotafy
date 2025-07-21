# Imagem oficial do PHP (exemplo com PHP 8.2)
FROM php:8.2-cli

# Instala dependências e extensões necessárias
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    curl \
    git \
    unzip \
    && docker-php-ext-install intl zip gd pdo pdo_mysql exif \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instala o Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Instala Node.js e npm
RUN curl -fsSL https://deb.nodesource.com/setup_18.x | bash - \
    && apt-get install -y nodejs \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instala dependências para ffmpeg, python e yt-dlp
RUN apt-get update && \
    apt-get install -y \
        ffmpeg \
        python3 \
        python3-pip \
        python3-venv \
        python3-full \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instala yt-dlp (versão mais recente)
RUN pip install --break-system-packages yt-dlp

# Verifica instalações
RUN yt-dlp --version && ffmpeg -version

# Define diretório de trabalho
WORKDIR /app

# Copia arquivos do projeto
COPY . /app

# Instala dependências do Composer
RUN composer install --no-dev --optimize-autoloader

# Instala dependências do npm e builda assets
RUN npm install && npm run build

RUN npm install vite-plugin-static-copy --save-dev

# Cria diretórios necessários
RUN mkdir -p storage/app/public/musicas && \
    chmod -R 775 storage bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache

# Expõe porta
EXPOSE 8000

# Comando para rodar o servidor
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8001"]