-- Arquivo: sql/schema.sql
-- Modelo de Banco de Dados DDL para o Sistema SIGECA (PostgreSQL/Supabase)
-- Data: [Data de hoje]

-- 1. TABELA USUARIO (Será armazenada como 'usuario' no PostgreSQL)
CREATE TABLE usuario (
    id_usuario SERIAL PRIMARY KEY, 
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    telefone VARCHAR(20),
    tipo_perfil VARCHAR(20) NOT NULL CHECK (tipo_perfil IN ('Organizador', 'Participante'))
);

---

-- 2. TABELA EVENTO (Objeto central do sistema)
CREATE TABLE evento (
    id_evento SERIAL PRIMARY KEY,
    id_organizador INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE,
    local VARCHAR(255) NOT NULL,
    carga_horaria INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Aberto' CHECK (status IN ('Aberto', 'Encerrado', 'Cancelado')),
    
    FOREIGN KEY (id_organizador) REFERENCES usuario(id_usuario)
);

---

-- 3. TABELA INSCRICAO (Relacionamento M:N)
CREATE TABLE inscricao (
    id_inscricao SERIAL PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_evento INT NOT NULL,
    data_inscricao TIMESTAMP WITHOUT TIME ZONE NOT NULL, 
    
    CONSTRAINT uk_usuario_evento UNIQUE (id_usuario, id_evento),
    
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_evento) REFERENCES evento(id_evento)
);

---

-- 4. TABELA PRESENCA (Controle de validação)
CREATE TABLE presenca (
    id_presenca SERIAL PRIMARY KEY,
    id_inscricao INT NOT NULL,
    data_registro DATE NOT NULL,
    hora_registro TIME WITHOUT TIME ZONE NOT NULL, 
    ip_registro VARCHAR(45),
    
    CONSTRAINT uk_inscricao_dia UNIQUE (id_inscricao, data_registro),
    
    FOREIGN KEY (id_inscricao) REFERENCES inscricao(id_inscricao)
);

---

-- 5. TABELA CERTIFICADO (Armazenamento e rastreio)
CREATE TABLE certificado (
    id_certificado SERIAL PRIMARY KEY,
    id_inscricao INT NOT NULL UNIQUE,
    data_emissao TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    codigo_rastreio VARCHAR(50) NOT NULL UNIQUE,
    caminho_arquivo VARCHAR(255) NOT NULL,
    status_validacao VARCHAR(20) NOT NULL DEFAULT 'Pendente' CHECK (status_validacao IN ('Emitido', 'Pendente', 'Inválido')),
    
    FOREIGN KEY (id_inscricao) REFERENCES inscricao(id_inscricao)
);