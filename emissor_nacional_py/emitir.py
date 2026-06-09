import asyncio
import json
import sys
import logging
from playwright.async_api import async_playwright

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

async def emitir_nfse(data: dict):
    try:
        async with async_playwright() as p:
            browser = await p.chromium.launch(headless=True)
            page = await browser.new_page()
            
            try:
                # 1. Login
                logger.info("Acessando tela de Login...")
                await page.goto("https://www.nfse.gov.br/EmissorNacional/Login")
                await page.fill("#Inscricao", data.get("cnpj_prestador", ""))
                await page.fill("#Senha", data.get("senha_prestador", ""))
                await page.click("button[type=submit]")
                await page.wait_for_load_state("networkidle")
                
                if "Login" in page.url:
                    raise Exception("Falha no login. Verifique as credenciais.")

                # 2. Pessoas
                logger.info("Acessando tela de Pessoas...")
                await page.goto("https://www.nfse.gov.br/EmissorNacional/DPS/Pessoas")
                await page.fill("#DataCompetencia", data.get("data_competencia", ""))
                await page.keyboard.press("Tab")
                await page.wait_for_timeout(2000)
                
                logger.info("Preenchendo CNPJ do Tomador...")
                cnpj_tomador = data.get("cnpj_tomador", "")
                await page.type("#Tomador_Inscricao", cnpj_tomador, delay=50)
                await page.keyboard.press("Tab")
                
                await page.wait_for_timeout(5000)
                
                # Se os dados não carregaram automaticamente, força o preenchimento manual do endereço do CRM
                is_visible = await page.locator("#Tomador_Nome").is_visible()
                if is_visible:
                    val = await page.locator("#Tomador_Nome").input_value()
                    if not val:
                        logger.warning("Campos vazios. Preenchendo endereço do tomador manualmente...")
                        await page.fill("#Tomador_Nome", data.get("nome_tomador", ""))
                        await page.evaluate("document.querySelector('#Tomador_InformarEndereco').click()")
                        await page.wait_for_timeout(1000)
                        
                        await page.fill("#Tomador_EnderecoNacional_CEP", data.get("cep_tomador", ""))
                        await page.wait_for_timeout(1000)
                        await page.fill("#Tomador_EnderecoNacional_Logradouro", data.get("logradouro_tomador", ""))
                        await page.fill("#Tomador_EnderecoNacional_Numero", data.get("numero_tomador", ""))
                        await page.fill("#Tomador_EnderecoNacional_Bairro", data.get("bairro_tomador", ""))
                        
                        codigo_mun_tomador = data.get("codigo_municipio_tomador", "3539301")
                        nome_mun_tomador = data.get("nome_municipio_tomador", "Pirassununga")
                        await page.evaluate(f"""
                            var newOption = new Option('{nome_mun_tomador}', '{codigo_mun_tomador}', true, true);
                            $('#Tomador_EnderecoNacional_CodigoMunicipio').append(newOption).trigger('change');
                        """)
                
                await page.evaluate("document.querySelector('#btnAvancar').click()")
                await page.wait_for_load_state("networkidle")
                await page.wait_for_timeout(3000)
                
                if "Pessoas" in page.url:
                    erros = await page.evaluate("Array.from(document.querySelectorAll('.field-validation-error, .alert')).map(e => e.innerText)")
                    raise Exception(f"Falha ao avançar de Pessoas para Serviços. Erros: {erros}")

                # 3. Serviços
                logger.info("Preenchendo Serviços...")
                
                codigo_municipio_prestacao = data.get("codigo_municipio_prestacao", "3539301")
                await page.evaluate(f"""
                    if ($('#LocalPrestacao_CodigoMunicipioPrestacao').find("option[value='{codigo_municipio_prestacao}']").length) {{
                        $('#LocalPrestacao_CodigoMunicipioPrestacao').val('{codigo_municipio_prestacao}').trigger('change');
                    }} else {{ 
                        var newOption = new Option('Pirassununga', '{codigo_municipio_prestacao}', true, true);
                        $('#LocalPrestacao_CodigoMunicipioPrestacao').append(newOption).trigger('change');
                    }}
                """)
                await page.wait_for_timeout(1000)
                
                codigo_ctn = data.get("codigo_ctn", "01.01.01")
                await page.evaluate(f"""
                    if ($('#ServicoPrestado_CodigoTributacaoNacional').find("option[value='{codigo_ctn}']").length) {{
                        $('#ServicoPrestado_CodigoTributacaoNacional').val('{codigo_ctn}').trigger('change');
                    }} else {{ 
                        var newOption = new Option('{codigo_ctn}', '{codigo_ctn}', true, true);
                        $('#ServicoPrestado_CodigoTributacaoNacional').append(newOption).trigger('change');
                    }}
                """)
                await page.wait_for_timeout(1000)
                
                await page.evaluate("$('input[name=\"ServicoPrestado.HaExportacaoImunidadeNaoIncidencia\"][value=\"0\"]').prop('checked', true).trigger('change')")
                await page.wait_for_timeout(1000)
                
                descricao = data.get("descricao_servico", "")
                descricao_escaped = json.dumps(descricao)
                await page.evaluate(f"$('#ServicoPrestado_Descricao').val({descricao_escaped})")
                
                # Avançar
                await page.evaluate("document.querySelector('button[type=\"submit\"].btn-primary').click()")
                await page.wait_for_load_state("networkidle")
                await page.wait_for_timeout(3000)
                
                if "Servico" in page.url:
                    erros = await page.evaluate("Array.from(document.querySelectorAll('.field-validation-error, .alert')).map(e => e.innerText)")
                    raise Exception(f"Falha ao avançar de Serviços para Valores. Erros: {erros}")

                # 4. Valores
                logger.info("Preenchendo Valores...")
                
                valor_servico = float(data.get("valor_servico", 0))
                valor_str = f"{valor_servico:.2f}".replace('.', ',')
                await page.fill("#Valores_ValorServico", valor_str)
                await page.keyboard.press("Tab")
                await page.wait_for_timeout(1000)
                
                # Exigibilidade do ISSQN: "Não" ou Exigível (Geralmente 1 para Exigível e 2 para Não Incidência/Isento/Imune)
                # Vamos injetar check no radio correspondente: "Não" (Retenção e Exigibilidade)
                # Se for selects, preenchemos; se for radio, setamos via prop('checked')
                # Assumindo ID padrão: Valores_RetencaoISSQN (radio 0)
                await page.evaluate("$('input[name=\"Valores.RetencaoISSQN\"][value=\"0\"]').prop('checked', true).trigger('change')")
                
                # Exigibilidade do ISSQN (Select): "1" = Exigível, "2" = Não incidência... Se o usuário quer "Não", vamos assumir que o sistema deixa "Não" por padrão ou selecionar a opção correta.
                # Serviço amparado por benefício: "Não" (value="0")
                await page.evaluate("$('input[name=\"Valores.Beneficio\"][value=\"0\"]').prop('checked', true).trigger('change')")
                
                # Tributação Federal
                is_optante_simples = data.get("is_optante_simples", False)
                
                if is_optante_simples:
                    valor_irrf = valor_servico * 0.015
                    await page.fill("#TributacaoFederal_ValorIRRF", f"{valor_irrf:.2f}".replace('.', ','))
                else:
                    valor_pis = valor_servico * 0.0065
                    valor_cofins = valor_servico * 0.03
                    valor_irrf = valor_servico * 0.015
                    await page.fill("#TributacaoFederal_ValorPIS", f"{valor_pis:.2f}".replace('.', ','))
                    await page.fill("#TributacaoFederal_ValorCOFINS", f"{valor_cofins:.2f}".replace('.', ','))
                    await page.fill("#TributacaoFederal_ValorIRRF", f"{valor_irrf:.2f}".replace('.', ','))
                
                await page.wait_for_timeout(1000)
                
                # Clica em Avançar para Rascunho
                await page.evaluate("document.querySelector('button[type=\"submit\"].btn-primary').click()")
                await page.wait_for_load_state("networkidle")
                await page.wait_for_timeout(3000)
                
                result = {
                    "status": "sucesso",
                    "mensagem": "NFS-e gerada como rascunho com sucesso."
                }
                print(json.dumps(result))
                
            finally:
                await browser.close()
                
    except Exception as e:
        logger.error(f"Erro na emissão: {e}")
        error_result = {
            "status": "erro",
            "detail": str(e)
        }
        print(json.dumps(error_result))
        sys.exit(1)

if __name__ == "__main__":
    if len(sys.argv) > 1:
        payload_str = sys.argv[1]
        try:
            data = json.loads(payload_str)
            asyncio.run(emitir_nfse(data))
        except Exception as ex:
            print(json.dumps({"status": "erro", "detail": "Invalid JSON input: " + str(ex)}))
            sys.exit(1)
    else:
        print(json.dumps({"status": "erro", "detail": "Missing JSON payload argument."}))
        sys.exit(1)
