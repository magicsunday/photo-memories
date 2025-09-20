FROM ubuntu:latest

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
    libonig-dev \
    libssl-dev \
    libtool \
    libxml2-dev \
    libzip-dev \
    patchelf \
    pkg-config \
    re2c \
    sudo \
    unzip \
    zlib1g-dev

RUN rm -rf /var/lib/apt/lists/*

RUN groupadd --gid ${GROUPID} memories && \
    useradd --uid ${USERID} --gid memories --create-home memories && \
    echo "memories ALL=(ALL) NOPASSWD:ALL" >> /etc/sudoers
