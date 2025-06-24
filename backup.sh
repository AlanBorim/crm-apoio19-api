#!/bin/bash

# Script simples para backup manual do banco de dados e arquivos do CRM Apoio19

# --- Configurações --- 
# Carregar variáveis do .env (se existir e for seguro)
ENV_FILE="/caminho/para/seu/crm_apoio19/.env" # AJUSTE O CAMINHO PARA O SEU ARQUIVO .env

if [ -f "$ENV_FILE" ]; then
    export $(grep -v 

"^#"

 "$ENV_FILE" | xargs)
fi

# Use variáveis de ambiente ou defina manualmente (menos seguro)
DB_HOST=${DB_HOST:-"SEU_HOST_MYSQL_AQUI"}
DB_PORT=${DB_PORT:-3306}
DB_DATABASE=${DB_DATABASE:-"SEU_BANCO_DE_DADOS_AQUI"}
DB_USERNAME=${DB_USERNAME:-"SEU_USUARIO_MYSQL_AQUI"}
DB_PASSWORD=${DB_PASSWORD:-"SUA_SENHA_MYSQL_AQUI"}

CRM_DIR="/caminho/para/seu/crm_apoio19" # AJUSTE O CAMINHO PARA O DIRETÓRIO DO CRM
BACKUP_DIR="/caminho/para/seus/backups" # AJUSTE O CAMINHO PARA O DIRETÓRIO DE BACKUPS
DATE_FORMAT=$(date +"%Y%m%d_%H%M%S")

# Nome dos arquivos de backup
DB_BACKUP_FILE="${BACKUP_DIR}/crm_db_backup_${DATE_FORMAT}.sql.gz"
FILES_BACKUP_FILE="${BACKUP_DIR}/crm_files_backup_${DATE_FORMAT}.tar.gz"

# --- Verificações ---
# Criar diretório de backup se não existir
mkdir -p "$BACKUP_DIR"

# Verificar se mysqldump está instalado
if ! command -v mysqldump &> /dev/null
then
    echo "Erro: O comando \"mysqldump\" não foi encontrado. Instale o cliente MySQL." >&2
    exit 1
fi

# Verificar se gzip está instalado
if ! command -v gzip &> /dev/null
then
    echo "Erro: O comando \"gzip\" não foi encontrado." >&2
    exit 1
fi

# Verificar se tar está instalado
if ! command -v tar &> /dev/null
then
    echo "Erro: O comando \"tar\" não foi encontrado." >&2
    exit 1
fi

# --- Execução do Backup ---

echo "Iniciando backup do banco de dados: $DB_DATABASE..."

# Exportar a senha de forma segura (se possível, use .my.cnf)
export MYSQL_PWD=$DB_PASSWORD

mysqldump --host=$DB_HOST --port=$DB_PORT --user=$DB_USERNAME $DB_DATABASE --no-tablespaces --single-transaction --quick | gzip > "$DB_BACKUP_FILE"

# Limpar a variável de senha
unset MYSQL_PWD

if [ $? -eq 0 ]; then
    echo "Backup do banco de dados concluído com sucesso: $DB_BACKUP_FILE"
else
    echo "Erro ao fazer backup do banco de dados!" >&2
    # Remover arquivo parcial se houver erro
    rm -f "$DB_BACKUP_FILE"
    exit 1
fi

echo "Iniciando backup dos arquivos do CRM em: $CRM_DIR..."

# Fazer backup dos arquivos da aplicação (excluindo vendor e talvez storage/logs)
tar -czf "$FILES_BACKUP_FILE" -C "$(dirname "$CRM_DIR")" "$(basename "$CRM_DIR")" --exclude="vendor" --exclude="storage/logs/*" --exclude=".git"

if [ $? -eq 0 ]; then
    echo "Backup dos arquivos concluído com sucesso: $FILES_BACKUP_FILE"
else
    echo "Erro ao fazer backup dos arquivos!" >&2
    # Remover arquivo parcial se houver erro
    rm -f "$FILES_BACKUP_FILE"
    exit 1
fi

# --- Limpeza de Backups Antigos (Opcional) ---
# Manter os últimos 7 backups, por exemplo
KEEP_BACKUPS=7
echo "Limpando backups antigos (mantendo os últimos $KEEP_BACKUPS)..."

# Limpar backups de banco de dados
ls -1t "${BACKUP_DIR}/crm_db_backup_"*.sql.gz | tail -n +$(($KEEP_BACKUPS + 1)) | xargs -r rm

# Limpar backups de arquivos
ls -1t "${BACKUP_DIR}/crm_files_backup_"*.tar.gz | tail -n +$(($KEEP_BACKUPS + 1)) | xargs -r rm

echo "Processo de backup concluído."

exit 0

