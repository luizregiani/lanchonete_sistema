-- Instalação NOVA. Importe pelo phpMyAdmin: aba "Importar" (ou cole na aba SQL).
-- Se você já tem o banco criado, não precisa importar nada: o sistema atualiza as tabelas sozinho.
-- (Alternativa manual: atualizar_banco.sql)
CREATE DATABASE IF NOT EXISTS sistema_empresa_regiani CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sistema_empresa_regiani;

CREATE TABLE IF NOT EXISTS usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  usuario VARCHAR(40) NOT NULL UNIQUE,
  senha VARCHAR(255) NOT NULL,
  perfil ENUM('admin','atendente','cozinha','motoboy') NOT NULL DEFAULT 'atendente',
  ativo TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS categorias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS produtos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  categoria_id INT NOT NULL,
  nome VARCHAR(100) NOT NULL,
  descricao VARCHAR(255) NOT NULL DEFAULT '',
  preco DECIMAL(8,2) NOT NULL,
  imagem VARCHAR(40) NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_produto_nome (nome),
  INDEX idx_ativo_cat (ativo, categoria_id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
);

CREATE TABLE IF NOT EXISTS pedidos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cliente VARCHAR(80) NOT NULL,
  telefone VARCHAR(20) NOT NULL,
  endereco VARCHAR(200) NOT NULL,
  numero VARCHAR(10) NULL,
  complemento VARCHAR(60) NULL,
  bairro VARCHAR(80) NULL,
  pagamento ENUM('pix','cartao_credito','cartao_debito','dinheiro') NOT NULL DEFAULT 'dinheiro',
  troco_para DECIMAL(8,2) NULL,
  subtotal DECIMAL(8,2) NOT NULL,
  taxa DECIMAL(8,2) NOT NULL,
  total DECIMAL(8,2) NOT NULL,
  status ENUM('recebido','em preparo','saiu para entrega','entregue','cancelado') NOT NULL DEFAULT 'recebido',
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_status (status, criado_em)
);

CREATE TABLE IF NOT EXISTS itens_pedido (
  id INT AUTO_INCREMENT PRIMARY KEY,
  pedido_id INT NOT NULL,
  produto_id INT NOT NULL,
  quantidade INT NOT NULL,
  preco_unit DECIMAL(8,2) NOT NULL,
  INDEX idx_produto (produto_id),
  FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
  FOREIGN KEY (produto_id) REFERENCES produtos(id)
);

CREATE TABLE IF NOT EXISTS log_acoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  acao VARCHAR(40) NOT NULL,
  detalhe VARCHAR(120),
  ip VARCHAR(45),
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login (acao, ip, criado_em)
);

CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(40) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL DEFAULT ''
);

INSERT IGNORE INTO categorias (id, nome) VALUES (1,'Lanches'),(2,'Porções'),(3,'Bebidas'),(4,'Sobremesas');

INSERT IGNORE INTO produtos (categoria_id, nome, descricao, preco) VALUES
(1,'X-Burguer Clássico','Pão brioche, blend 150g e queijo.',22.00),
(1,'X-Bacon','Blend 150g, queijo prato e bacon crocante.',26.00),
(1,'X-Salada','Blend 150g, queijo, alface, tomate e maionese.',24.00),
(1,'Duplo Cheddar','Dois blends de 120g e cheddar cremoso.',32.00),
(1,'Frango Crocante','Filé de frango empanado, queijo e molho especial.',25.00),
(2,'Batata Frita','Porção 400g com sal e orégano.',18.00),
(2,'Onion Rings','Anéis de cebola empanados, 300g.',20.00),
(2,'Nuggets (12 un.)','Com molho barbecue.',19.00),
(3,'Refrigerante Lata','350ml, vários sabores.',6.00),
(3,'Suco Natural','Laranja ou limão, 400ml.',9.00),
(3,'Milkshake','Chocolate, morango ou baunilha.',16.00),
(4,'Brownie com Sorvete','Brownie quente e sorvete de creme.',15.00);

-- Administrador inicial: usuário "admin" / senha "Admin@2026"
-- TROQUE A SENHA no primeiro acesso: Configurações > Alterar minha senha.
-- (só é criado se a tabela de usuários estiver vazia)
INSERT INTO usuarios (nome, usuario, senha, perfil)
SELECT 'Administrador', 'admin', '$2y$10$b5tNZoA5OuEyT4MTxSIhDu6dN7HvU6CmoUcitVuEp/NJU33Se7Hfq', 'admin'
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM usuarios);
