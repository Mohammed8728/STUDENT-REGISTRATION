-- LAB 04 - E-Commerce Product Entry
CREATE DATABASE IF NOT EXISTS lab04_shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE lab04_shop;

CREATE TABLE IF NOT EXISTS categories (
    id   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(60) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB;
INSERT IGNORE INTO categories (name) VALUES ('Electronics'), ('Clothing'), ('Food & Drinks'), ('Household'), ('Stationery');

CREATE TABLE IF NOT EXISTS products (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(100) NOT NULL,
    sku          VARCHAR(40)  NOT NULL,                              -- stored upper-case
    category_id  TINYINT UNSIGNED NOT NULL,
    unit_price   DECIMAL(12,2) NOT NULL,                             -- XAF
    quantity     INT UNSIGNED NOT NULL DEFAULT 0,
    description  VARCHAR(1000) NULL,
    image_file   VARCHAR(40)  NULL,                                  -- server-generated file name only (e.g. 3f9a...c1.jpg)
    is_available TINYINT(1)   NOT NULL DEFAULT 0,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_product_sku (sku),                                 -- no duplicate SKUs
    CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT chk_price CHECK (unit_price > 0)
) ENGINE=InnoDB;
