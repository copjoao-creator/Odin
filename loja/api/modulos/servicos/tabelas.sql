-- Módulo de serviços: cadastro (com 1 foto PNG/JPG de no máximo 250x250 pixels) e assinaturas dos serviços recorrentes.

CREATE TABLE IF NOT EXISTS servicos (
  codigo_servico VARCHAR(40) NOT NULL,
  categoria VARCHAR(80) NOT NULL,
  subcategoria VARCHAR(80) NOT NULL DEFAULT '',
  descricao TEXT NOT NULL,
  preco_custo DECIMAL(10,2) NOT NULL DEFAULT 0,
  preco_venda DECIMAL(10,2) NOT NULL,
  recorrente TINYINT(1) NOT NULL DEFAULT 0,
  renovacao ENUM('unica','mensal','trimestral','semestral','anual') NOT NULL DEFAULT 'unica',
  foto VARCHAR(80) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (codigo_servico),
  KEY ix_servicos_categoria (categoria, subcategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma assinatura nasce quando um serviço recorrente é pago. Com mp_assinatura, o Mercado Pago cobra
-- sozinho no cartão a cada período. Sem ela (assinaturas antigas), o sistema gera
-- um pedido de renovação e envia ao cliente o link para pagar (Pix, boleto ou cartão).
CREATE TABLE IF NOT EXISTS assinaturas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cliente_cpf CHAR(11) NOT NULL,
  codigo_servico VARCHAR(40) NOT NULL,
  descricao VARCHAR(255) NOT NULL,
  renovacao ENUM('mensal','trimestral','semestral','anual') NOT NULL,
  valor DECIMAL(10,2) NOT NULL,
  status ENUM('ativa','atrasada','cancelada') NOT NULL DEFAULT 'ativa',
  inicio DATE NOT NULL,
  proxima_cobranca DATE NOT NULL,
  pedido_origem INT UNSIGNED NOT NULL,
  pedido_renovacao INT UNSIGNED NULL,
  mp_assinatura VARCHAR(40) NULL,
  cancelada_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_assinaturas_cobranca (status, proxima_cobranca),
  KEY ix_assinaturas_cliente (cliente_cpf),
  KEY ix_assinaturas_mp (mp_assinatura),
  CONSTRAINT fk_assinaturas_cliente FOREIGN KEY (cliente_cpf) REFERENCES clientes (cpf) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
