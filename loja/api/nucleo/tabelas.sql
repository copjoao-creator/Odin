-- Núcleo financeiro da loja Odin Focus: configurações, administradores, clientes, pedidos e pagamentos.
-- Compatível com MySQL 5.7+ e MariaDB 10.3+. O instalar.php executa este arquivo sozinho.

CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(60) NOT NULL,
  valor TEXT NOT NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- perfil: "tecnico" (quem instala e mantém: vê as chaves de pagamento) ou "administrador" (o dono da loja).
CREATE TABLE IF NOT EXISTS administradores (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  senha_hash VARCHAR(255) NOT NULL,
  perfil VARCHAR(20) NOT NULL DEFAULT 'administrador',
  ultimo_acesso DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_administradores_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_tentativas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARCHAR(45) NOT NULL,
  momento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_login_tentativas (ip, momento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CPF e e-mail identificam o cliente: o CPF é a chave primária e o e-mail é único.
CREATE TABLE IF NOT EXISTS clientes (
  cpf CHAR(11) NOT NULL,
  email VARCHAR(190) NOT NULL,
  nome VARCHAR(150) NOT NULL,
  cep CHAR(8) NOT NULL,
  rua VARCHAR(150) NOT NULL,
  numero VARCHAR(20) NOT NULL,
  complemento VARCHAR(80) NULL,
  bairro VARCHAR(100) NOT NULL,
  cidade VARCHAR(100) NOT NULL,
  estado CHAR(2) NOT NULL,
  celular VARCHAR(11) NOT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (cpf),
  UNIQUE KEY uk_clientes_email (email),
  KEY ix_clientes_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- O endereço é copiado para o pedido: mudanças posteriores no cadastro não alteram pedidos antigos.
CREATE TABLE IF NOT EXISTS pedidos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token CHAR(32) NOT NULL,
  cliente_cpf CHAR(11) NOT NULL,
  origem ENUM('loja','admin','renovacao') NOT NULL DEFAULT 'loja',
  status ENUM('aguardando_pagamento','em_analise','pago','cancelado','estornado') NOT NULL DEFAULT 'aguardando_pagamento',
  subtotal DECIMAL(10,2) NOT NULL,
  frete DECIMAL(10,2) NOT NULL DEFAULT 0,
  total DECIMAL(10,2) NOT NULL,
  custo_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  precisa_entrega TINYINT(1) NOT NULL DEFAULT 0,
  cep CHAR(8) NOT NULL,
  rua VARCHAR(150) NOT NULL,
  numero VARCHAR(20) NOT NULL,
  complemento VARCHAR(80) NULL,
  bairro VARCHAR(100) NOT NULL,
  cidade VARCHAR(100) NOT NULL,
  estado CHAR(2) NOT NULL,
  forma_pagamento ENUM('pix','boleto','credito','debito','outro') NULL,
  mp_assinatura VARCHAR(40) NULL,
  assinatura_data_final DATE NULL,
  dados_protegidos TINYINT(1) NOT NULL DEFAULT 0,
  efeitos_aplicados TINYINT(1) NOT NULL DEFAULT 0,
  pago_em DATETIME NULL,
  cancelado_em DATETIME NULL,
  observacoes TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_pedidos_status (status, criado_em),
  KEY ix_pedidos_pago_em (pago_em),
  KEY ix_pedidos_cliente (cliente_cpf),
  CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_cpf) REFERENCES clientes (cpf) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens guardam descrição, preço e custo do momento da venda (o catálogo pode mudar depois).
CREATE TABLE IF NOT EXISTS pedido_itens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  descricao VARCHAR(255) NOT NULL,
  quantidade INT UNSIGNED NOT NULL,
  preco_unitario DECIMAL(10,2) NOT NULL,
  custo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0,
  renovacao VARCHAR(12) NULL,
  referencia INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_pedido_itens_pedido (pedido_id),
  KEY ix_pedido_itens_codigo (tipo, codigo),
  CONSTRAINT fk_pedido_itens_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada tentativa de pagamento no Asaas (Pix, boleto ou cartão de crédito). mp_id: id da cobrança
-- ("pay_…") ou do parcelamento no Asaas. app: "asaas"; "loja"/"servicos" são pagamentos antigos do Mercado Pago.
CREATE TABLE IF NOT EXISTS pagamentos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pedido_id INT UNSIGNED NOT NULL,
  mp_id VARCHAR(60) NOT NULL,
  app VARCHAR(12) NOT NULL DEFAULT 'asaas',
  metodo ENUM('pix','boleto','credito','debito','outro') NOT NULL,
  mp_metodo VARCHAR(40) NULL,
  status VARCHAR(30) NOT NULL,
  status_detalhe VARCHAR(80) NULL,
  valor DECIMAL(10,2) NOT NULL,
  valor_liquido DECIMAL(10,2) NULL,
  valor_estornado DECIMAL(10,2) NOT NULL DEFAULT 0,
  parcelas SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pix_copia_cola TEXT NULL,
  pix_qr_base64 MEDIUMTEXT NULL,
  link_pagamento VARCHAR(500) NULL,
  codigo_barras VARCHAR(100) NULL,
  expira_em DATETIME NULL,
  aprovado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pagamentos_mp (mp_id),
  KEY ix_pagamentos_pedido (pedido_id),
  CONSTRAINT fk_pagamentos_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
