-- 1. Tabela USUARIO (Organizadores e Participantes)
CREATE TABLE usuario (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    -- Armazenar hash da senha (e.g., usando password_hash() do PHP)
    senha VARCHAR(255) NOT NULL,
    telefone VARCHAR(20) NULL,
    -- Perfis: Organizador ou Participante [cite: 208]
    tipo_perfil ENUM('Organizador', 'Participante') NOT NULL
);

-- 2. Tabela EVENTO
CREATE TABLE evento (
    id_evento INT AUTO_INCREMENT PRIMARY KEY,
    id_organizador INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT NOT NULL,
    data_inicio DATE NOT NULL,
    data_fim DATE NULL,
    local VARCHAR(255) NOT NULL,
    carga_horaria INT NOT NULL,
    -- Status: Pendente (aguardando aprovação root), Aberto (aprovado), Encerrado, Cancelado
    status ENUM('Pendente', 'Aberto', 'Encerrado', 'Cancelado') NOT NULL DEFAULT 'Pendente',
    
    -- Relacionamento com Organizador
    FOREIGN KEY (id_organizador) REFERENCES usuario(id_usuario)
);

-- 3. Tabela INSCRICAO (Relacionamento N:M entre Usuário e Evento)
CREATE TABLE inscricao (
    id_inscricao INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL, -- O participante inscrito
    id_evento INT NOT NULL,
    data_inscricao DATETIME NOT NULL,
    
    -- Garante que um usuário não se inscreva mais de uma vez no mesmo evento
    UNIQUE KEY uk_usuario_evento (id_usuario, id_evento),
    
    -- Relacionamentos
    FOREIGN KEY (id_usuario) REFERENCES usuario(id_usuario),
    FOREIGN KEY (id_evento) REFERENCES evento(id_evento)
);

-- 4. Tabela PRESENCA (Registro diário da presença)
CREATE TABLE presenca (
    id_presenca INT AUTO_INCREMENT PRIMARY KEY,
    id_inscricao INT NOT NULL,
    data_registro DATE NOT NULL,
    hora_entrada TIME NULL,
    hora_saida TIME NULL,
    ip_entrada VARCHAR(45) NULL, -- Para controle de rede local [cite: 166, 173]
    ip_saida VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    
    -- Garante apenas um registro de presença por inscrição por dia
    UNIQUE KEY uq_presenca_inscricao_dia (id_inscricao, data_registro),
    
    -- Relacionamento: ON DELETE CASCADE garante que se a inscrição for cancelada, os registros de presença sumam.
    FOREIGN KEY (id_inscricao) REFERENCES inscricao(id_inscricao) ON DELETE CASCADE
);

-- 5. Tabela CERTIFICADO_IMAGEM_FUNDO (Templates de design)
CREATE TABLE certificado_imagem_fundo (
    id_imagem_fundo INT AUTO_INCREMENT PRIMARY KEY,
    nome_arquivo_unico VARCHAR(255) NOT NULL,
    -- Armazenar o binário da imagem ou, alternativamente, o caminho para o arquivo no disco
    conteudo_imagem LONGBLOB NOT NULL, 
    data_upload DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 6. Tabela CERTIFICADO (Emissão e Rastreabilidade)
CREATE TABLE certificado (
    id_certificado INT AUTO_INCREMENT PRIMARY KEY,
    id_inscricao INT NOT NULL UNIQUE, -- 1 certificado por 1 inscrição
    id_evento INT NULL, 
    id_imagem_fundo INT NULL,
    data_emissao DATETIME NOT NULL,
    -- Código único para rastreabilidade e validação pública [cite: 28]
    codigo_rastreio VARCHAR(64) NOT NULL UNIQUE, 
    codigo_hash VARCHAR(128) NULL, -- Hash de integridade do conteúdo do certificado
    caminho_arquivo TEXT NULL, -- Caminho onde o PDF/Imagem do certificado está salvo
    -- Status do certificado
    status_validacao ENUM('PENDENTE', 'EMITIDO', 'VALIDADO', 'CANCELADO') NOT NULL DEFAULT 'PENDENTE',
    
    -- Relacionamentos:
    -- ON DELETE CASCADE: Se a inscrição for excluída, o certificado é excluído.
    FOREIGN KEY (id_inscricao) REFERENCES inscricao(id_inscricao) ON DELETE CASCADE,
    FOREIGN KEY (id_evento) REFERENCES evento(id_evento) ON DELETE SET NULL,
    FOREIGN KEY (id_imagem_fundo) REFERENCES certificado_imagem_fundo(id_imagem_fundo) ON DELETE SET NULL
);