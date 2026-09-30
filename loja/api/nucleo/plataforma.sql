-- Plataforma Odin Focus: cadastro das lojas e administrador master (tabelas sem prefixo de loja).
-- O sistema cria estas tabelas sozinho na primeira chamada (Plataforma::instalar).

CREATE TABLE IF NOT EXISTS plataforma_config (
  chave VARCHAR(60) NOT NULL,
  valor TEXT NOT NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada loja: endereço (odinfocus.com.br/{slug}/), prefixo das tabelas dela e situação.
-- A loja 1 é a original: endereço "loja" e tabelas sem prefixo.
CREATE TABLE IF NOT EXISTS plataforma_lojas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(40) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  prefixo VARCHAR(12) NOT NULL,
  status ENUM('ativa','suspensa') NOT NULL DEFAULT 'ativa',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_plataforma_lojas_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Administrador master: acesso total a todas as lojas. Os códigos para criar, alterar ou
-- recuperar a senha vão para o e-mail de verificação (não para o e-mail de login).
CREATE TABLE IF NOT EXISTS plataforma_admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  email_verificacao VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NULL,
  ultimo_acesso DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_plataforma_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de 6 dígitos enviados ao e-mail de verificação (valem 15 minutos, 5 tentativas).
CREATE TABLE IF NOT EXISTS plataforma_codigos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  finalidade VARCHAR(20) NOT NULL,
  codigo_hash VARCHAR(255) NOT NULL,
  expira_em DATETIME NOT NULL,
  tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
  usado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_plataforma_codigos_admin (admin_id, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tentativas de login e pedidos de código, por IP (limite contra força bruta).
CREATE TABLE IF NOT EXISTS plataforma_tentativas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  chave VARCHAR(80) NOT NULL,
  momento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_plataforma_tentativas (chave, momento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
