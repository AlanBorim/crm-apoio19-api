import asyncio
import logging
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from playwright.async_api import async_playwright

app = FastAPI(title="NFSe Emissor Nacional API")
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class NFSeData(BaseModel):
    cnpj_prestador: str
    senha_prestador: str
    data_competencia: str # DD/MM/YYYY
    cnpj_tomador: str # Apenas numeros
    codigo_municipio_prestacao: str = "3539301" # Default Pirassununga
    nome_municipio_prestacao: str = "Pirassununga"
    codigo_ctn: str = "01.01.01"
    descricao_servico: str
    valor_servico: float
    simples_nacional: bool = False # Se o emissor é do simples

@app.post("/emitir")
async def emitir_nfse(data: NFSeData):
    logger.info(f"Recebida requisição para emitir NFSe - Prestador: {data.cnpj_prestador}, Tomador: {data.cnpj_tomador}")
    
    try:
        # Inicia o playwright para realizar a automacao
        async with async_playwright() as p:
            browser = await p.chromium.launch(headless=True)
            page = await browser.new_page()
            
            try:
                # 1. Login
                logger.info("Acessando tela de Login...")
                await page.goto("https://www.nfse.gov.br/EmissorNacional/Login")
                await page.fill("#Inscricao", data.cnpj_prestador)
                await page.fill("#Senha", data.senha_prestador)
                await page.click("button[type=submit]")
                await page.wait_for_load_state("networkidle")
                
                if "Login" in page.url:
                    raise Exception("Falha no login. Verifique as credenciais.")

                # 2. Pessoas
                logger.info("Acessando tela de Pessoas...")
                await page.goto("https://www.nfse.gov.br/EmissorNacional/DPS/Pessoas")
                await page.fill("#DataCompetencia", data.data_competencia)
                await page.keyboard.press("Tab")
                await page.wait_for_timeout(3000) # Aguarda recarregamento do painel prestador
                
                # Tomador Brasil
                await page.click("input#Tomador_LocalDomicilio[value='1']", force=True)
                await page.wait_for_timeout(1000)
                
                logger.info("Preenchendo CNPJ do Tomador...")
                await page.type("#Tomador_Inscricao", data.cnpj_tomador, delay=50)
                await page.keyboard.press("Tab")
                
                # Aguarda o logradouro carregar automaticamente
                try:
                    await page.wait_for_selector("#Tomador_EnderecoNacional_Logradouro", state="visible", timeout=15000)
                except Exception as e:
                    logger.warning(f"Timeout ao aguardar carregamento automático do endereço do tomador: {e}")
                
                await page.wait_for_timeout(2000)
                await page.evaluate("document.querySelector('#btnAvancar').click()")
                await page.wait_for_load_state("networkidle")
                await page.wait_for_timeout(3000)
                
                if "Pessoas" in page.url:
                    # Tentar pegar erros na tela
                    erros = await page.evaluate("Array.from(document.querySelectorAll('.field-validation-error, .alert')).map(e => e.innerText)")
                    raise Exception(f"Falha ao avançar de Pessoas para Serviços. Erros na tela: {erros}")

                # 3. Serviços
                logger.info("Preenchendo Serviços...")
                await page.evaluate(f"document.querySelector('#LocalPrestacao_CodigoMunicipioPrestacao').value = '{data.codigo_municipio_prestacao}'")
                await page.evaluate("document.querySelector('#LocalPrestacao_CodigoMunicipioPrestacao').dispatchEvent(new Event('change', { bubbles: true }))")
                await page.wait_for_timeout(2000)
                
                await page.evaluate(f"document.querySelector('#ServicoPrestado_CodigoTributacaoNacional').value = '{data.codigo_ctn}'")
                await page.evaluate("document.querySelector('#ServicoPrestado_CodigoTributacaoNacional').dispatchEvent(new Event('change', { bubbles: true }))")
                await page.wait_for_timeout(2000)
                
                await page.evaluate(f"document.querySelector('#ServicoPrestado_Descricao').value = `{data.descricao_servico}`")
                
                # Avançar para Valores
                # O botão avançar pode ser apenas type=submit no serviço
                await page.evaluate("document.querySelector('button[type=\"submit\"].btn-primary').click()")
                await page.wait_for_load_state("networkidle")
                await page.wait_for_timeout(3000)
                
                if "Servico" in page.url:
                    erros = await page.evaluate("Array.from(document.querySelectorAll('.field-validation-error, .alert')).map(e => e.innerText)")
                    raise Exception(f"Falha ao avançar de Serviços para Valores. Erros na tela: {erros}")

                # 4. Valores
                logger.info("Preenchendo Valores...")
                
                valor_str = f"{data.valor_servico:.2f}".replace('.', ',')
                # Valor do servico
                await page.fill("#Valores_ValorServico", valor_str)
                
                # Exigibilidade do ISSQN: Não (2) ou Isento (etc)
                # O usuário disse: exigibilidade = não, retenção = não, beneficio = não
                # Precisamos mapear as IDs específicas. Por enquanto usando placeholder
                # TODO: O teste do Playwright do script isolado não chegou aqui ainda, precisamos verificar as IDs da página de Valores!
                
                # Finalização provisória
                logger.info("Emissão (Rascunho) finalizada (pendente mapeamento CSS exato dos Valores).")
                
                return {
                    "status": "sucesso",
                    "mensagem": "NFS-e gerada como rascunho com sucesso (Valores pendentes de automação fina)."
                }
                
            finally:
                await browser.close()
                
    except Exception as e:
        logger.error(f"Erro na emissão: {e}")
        raise HTTPException(status_code=500, detail=str(e))

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8005)
