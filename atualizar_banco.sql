-- ATUALIZAÇÃO MANUAL (opcional): o sistema já faz isto sozinho ao abrir qualquer página.
-- Use se preferir rodar à mão: no phpMyAdmin, CLIQUE NO SEU BANCO na lista da esquerda
-- e depois aba "SQL" ou "Importar". Mantém todos os pedidos e produtos.
-- Requer MariaDB (padrão do XAMPP).

ALTER TABLE produtos
  ADD COLUMN IF NOT EXISTS imagem VARCHAR(40) NULL AFTER preco;

ALTER TABLE pedidos
  ADD COLUMN IF NOT EXISTS numero VARCHAR(10) NULL AFTER endereco,
  ADD COLUMN IF NOT EXISTS complemento VARCHAR(60) NULL AFTER numero,
  ADD COLUMN IF NOT EXISTS bairro VARCHAR(80) NULL AFTER complemento,
  ADD COLUMN IF NOT EXISTS pagamento ENUM('pix','cartao_credito','cartao_debito','dinheiro') NOT NULL DEFAULT 'dinheiro' AFTER bairro,
  ADD COLUMN IF NOT EXISTS troco_para DECIMAL(8,2) NULL AFTER pagamento;

CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(40) PRIMARY KEY,
  valor VARCHAR(255) NOT NULL DEFAULT ''
);
