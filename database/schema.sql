-- Arquivo: sql/schema.sql
-- Modelo de Banco de Dados DDL para o Sistema SIGECA (PostgreSQL/Supabase)
-- Atualizado: adiciona suporte a check-in/check-out (hora_entrada, hora_saida, ip) e corrige certificado
-- Data: [Data de hoje]

-- 1. TABELA USUARIO
CREATE TABLE IF NOT EXISTS usuario (
    id_usuario SERIAL PRIMARY KEY, 
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    telefone VARCHAR(20),
    tipo_perfil VARCHAR(20) NOT NULL CHECK (tipo_perfil IN ('Organizador', 'Participante'))
);

-- 2. TABELA EVENTO
CREATE TABLE IF NOT EXISTS evento (
    id_evento SERIAL PRIMARY KEY,
    id_organizador INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE,
    local VARCHAR(255) NOT NULL,
    carga_horaria INT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Aberto' CHECK (status IN ('Aberto', 'Encerrado', 'Cancelado')),
    FOREIGN KEY (id_organizador) REFERENCES usuario(id_usuario) ON DELETE CASCADE
);

-- 3. TABELA INSCRICAO
CREATE TABLE IF NOT EXISTS inscricao (
    id_inscricao SERIAL PRIMARY KEY,
    id_usuario INT NOT NULL,
    id_evento INT NOT NULL,
    data_inscricao TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    CONSTRAINT uk_usuario_evento UNIQUE (id_usuario, id_evento),
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
    FOREIGN KEY (id_evento) REFERENCES evento(id_evento) ON DELETE CASCADE
);

-- 4. TABELA PRESENCA (ajustada para suportar entrada/saída e IPs)
CREATE TABLE IF NOT EXISTS presenca (
    id_presenca SERIAL PRIMARY KEY,
    id_inscricao INT NOT NULL,
    data_registro DATE NOT NULL,
    hora_entrada TIME WITHOUT TIME ZONE,
    hora_saida TIME WITHOUT TIME ZONE,
    ip_entrada VARCHAR(45),
    ip_saida VARCHAR(45),
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_presenca_inscricao_dia UNIQUE (id_inscricao, data_registro),
    FOREIGN KEY (id_inscricao) REFERENCES inscricao(id_inscricao) ON DELETE CASCADE
);

-- 5. TABELA CERTIFICADO (corrigida: declara id_inscricao e dados)
CREATE TABLE IF NOT EXISTS certificado (
    id_certificado SERIAL PRIMARY KEY,
    id_inscricao INT NOT NULL UNIQUE,
    emitido_em TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
    arquivo_url TEXT,          -- opcional: link/URL do arquivo armazenado
    dados JSONB,               -- opcional: metadados do certificado
    FOREIGN KEY (id_inscricao) REFERENCES inscricao(id_inscricao) ON DELETE CASCADE
);

-- Índices extras recomendados
CREATE INDEX IF NOT EXISTS idx_evento_data_inicio ON evento (data_inicio);
CREATE INDEX IF NOT EXISTS idx_inscricao_usuario ON inscricao (id_usuario);
CREATE INDEX IF NOT EXISTS idx_presenca_inscricao_data ON presenca (id_inscricao, data_registro);