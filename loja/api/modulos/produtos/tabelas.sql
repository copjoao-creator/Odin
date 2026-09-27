-- Módulo de produtos (mercadorias): cadastro, estoque e até 3 fotos PNG/JPG de no máximo 800x800 pixels.

CREATE TABLE IF NOT EXISTS produtos (
  codigo_produto VARCHAR(40) NOT NULL,
  categoria VARCHAR(80) NOT NULL,
  subcategoria VARCHAR(80) NOT NULL DEFAULT '',
  descricao TEXT NOT NULL,
  estoque INT NOT NULL DEFAULT 0,
  preco_custo DECIMAL(10,2) NOT NULL DEFAULT 0,
  preco_venda DECIMAL(10,2) NOT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (codigo_produto),
  KEY ix_produtos_categoria (categoria, subcategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS produto_fotos (
  codigo_produto VARCHAR(40) NOT NULL,
  posicao TINYINT UNSIGNED NOT NULL,
  arquivo VARCHAR(80) NOT NULL,
  largura SMALLINT UNSIGNED NOT NULL,
  altura SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (codigo_produto, posicao),
  CONSTRAINT fk_produto_fotos_produto FOREIGN KEY (codigo_produto) REFERENCES produtos (codigo_produto) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
