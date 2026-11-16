FROM ubuntu:latest@sha256:d66b366dac61b575bee2aef07e9c26e8070096fbe84a3d4d3eb0da069b5d76db

ARG USERID=1000
ARG GROUPID=1000

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    autoconf \
    automake \
    autopoint \
    bison \
    build-essential \
    ca-certificates \
    cmake \
    curl \
    ffmpeg \
    flex \
    git \
    imagemagick \
    libavif-dev \
    libc6-dev \
    libfreetype-dev \
    libheif-dev \
    libjpeg-dev \
    libpng-dev \
    libtool \
    libwebp-dev \
    libxml2 \
    libzip-dev \
    openssl \
    patchelf \
    re2c \
    sudo \
    unzip \
    zlib1g-dev

RUN rm -rf /var/lib/apt/lists/*

RUN groupadd --gid ${GROUPID} memories && \
    useradd --uid ${USERID} --gid memories --create-home memories && \
    echo "memories ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers
