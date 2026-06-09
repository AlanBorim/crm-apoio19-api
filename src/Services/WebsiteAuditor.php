<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Lead;

class WebsiteAuditor
{
    /**
     * Realiza a auditoria completa de um Lead (Perfil + Performance de Site + Tecnologias).
     *
     * @param Lead $lead
     * @return array Resultado detalhado da auditoria
     */
    public function auditAndScore(Lead $lead): array
    {
        $profileScore = 0;
        $performanceScore = 0;
        $techScore = 0;
        
        $painPoints = [];
        $technologies = [];
        $performance = [
            'online' => false,
            'ssl' => false,
            'response_time_ms' => null,
            'gzip' => false,
            'status_code' => null
        ];

        // ==========================================
        // 1. CÁLCULO DO FIT DE PERFIL (Máx: 30 pontos)
        // ==========================================
        // E-mail válido (+10)
        if (!empty($lead->email) && filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
            $profileScore += 10;
        } else {
            $painPoints[] = [
                'type' => 'warning',
                'title' => 'E-mail ausente ou inválido',
                'description' => 'O lead não possui um e-mail de contato válido cadastrado.'
            ];
        }

        // Telefone preenchido (+10)
        if (!empty($lead->phone)) {
            $profileScore += 10;
        } else {
            $painPoints[] = [
                'type' => 'warning',
                'title' => 'Telefone ausente',
                'description' => 'O lead não possui telefone de contato cadastrado.'
            ];
        }

        // Empresa preenchida (+5)
        if (!empty($lead->company)) {
            $profileScore += 5;
        }

        // Cargo preenchido (+5)
        if (!empty($lead->position)) {
            $profileScore += 5;
        }

        // ==========================================
        // 2. AUDITORIA DE SITE E PERFORMANCE (Máx: 40 pontos)
        // ==========================================
        $url = $lead->source_extra; // Campo site foi salvo em source_extra

        if (!empty($url)) {
            // Normalizar URL
            if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
                $url = "http://" . $url;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6); // Timeout razoável para auditoria on-demand
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) CRM-Apoio-Auditor/1.0');

            $startTime = microtime(true);
            $response = curl_exec($ch);
            $endTime = microtime(true);

            if ($response !== false) {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $connectTime = curl_getinfo($ch, CURLINFO_CONNECT_TIME);
                $ttfb = curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
                
                curl_close($ch);

                $responseTimeMs = round(($ttfb > 0 ? $ttfb : ($endTime - $startTime)) * 1000);
                
                $performance['online'] = ($statusCode >= 200 && $statusCode < 400);
                $performance['status_code'] = $statusCode;
                $performance['response_time_ms'] = $responseTimeMs;

                // Separar headers do body
                $headersText = substr($response, 0, $headerSize);
                $htmlBody = substr($response, $headerSize);

                if ($performance['online']) {
                    $performanceScore += 10; // Online (+10)

                    // Verificar SSL/HTTPS
                    $isSsl = (strpos(strtolower($effectiveUrl), 'https://') === 0);
                    $performance['ssl'] = $isSsl;
                    if ($isSsl) {
                        $performanceScore += 10; // SSL ativo (+10)
                    } else {
                        $painPoints[] = [
                            'type' => 'danger',
                            'title' => 'Conexão insegura (Sem SSL)',
                            'description' => 'O site do lead não utiliza o protocolo HTTPS seguro, o que prejudica a confiança e o SEO do site.'
                        ];
                    }

                    // Tempo de resposta (TTFB)
                    if ($responseTimeMs < 800) {
                        $performanceScore += 10; // Rápido (+10)
                    } elseif ($responseTimeMs <= 1800) {
                        $performanceScore += 5;  // Médio (+5)
                        $painPoints[] = [
                            'type' => 'warning',
                            'title' => 'Tempo de carregamento mediano',
                            'description' => "O site do lead demora {$responseTimeMs}ms para responder. Pode ser melhorado para otimizar conversões."
                        ];
                    } else {
                        $painPoints[] = [
                            'type' => 'danger',
                            'title' => 'Site muito lento',
                            'description' => "O site do lead demora {$responseTimeMs}ms para responder. Sites muito lentos perdem até 30% das vendas online."
                        ];
                    }

                    // Compressão Gzip/Brotli
                    $hasGzip = (preg_match('/content-encoding:\s*(gzip|deflate|br)/i', $headersText) === 1);
                    $performance['gzip'] = $hasGzip;
                    if ($hasGzip) {
                        $performanceScore += 10; // Compressão ativa (+10)
                    } else {
                        $painPoints[] = [
                            'type' => 'warning',
                            'title' => 'Sem compressão de dados',
                            'description' => 'O servidor do site não utiliza compactação gzip ou brotli, consumindo mais banda e aumentando o tempo de carregamento.'
                        ];
                    }

                    // ==========================================
                    // 3. MATURIDADE TECNOLÓGICA (Máx: 30 pontos)
                    // ==========================================
                    $detectedPixels = false;
                    $detectedCms = false;
                    $detectedCmsName = null;

                    // Mapeamento regex de tecnologias comuns (BuiltWith completo)
                    $techRegex = [
                        // CMS
                        'WordPress' => ['pattern' => '/wp-content|wp-includes|generator" content="WordPress/i', 'type' => 'cms'],
                        'Shopify' => ['pattern' => '/cdn\.shopify\.com|Shopify\.shop|shopify-payment-button/i', 'type' => 'cms'],
                        'Wix' => ['pattern' => '/wix\.com|wix-code|wix-image/i', 'type' => 'cms'],
                        'Magento' => ['pattern' => '/magento|catalog\/product|js\/mage/i', 'type' => 'cms'],
                        'Joomla' => ['pattern' => '/joomla|generator" content="Joomla/i', 'type' => 'cms'],
                        'Drupal' => ['pattern' => '/drupal|generator" content="Drupal/i', 'type' => 'cms'],
                        'Webflow' => ['pattern' => '/webflow/i', 'type' => 'cms'],
                        'Squarespace' => ['pattern' => '/squarespace\.com|static1\.squarespace\.com/i', 'type' => 'cms'],
                        'Loja Integrada' => ['pattern' => '/lojaintegrada\.com\.br|loja-integrada/i', 'type' => 'cms'],
                        'Tray' => ['pattern' => '/tray\.com\.br|tray-cdn/i', 'type' => 'cms'],
                        'Nuvemshop' => ['pattern' => '/nuvemshop\.com\.br|nuvemshop|tiendanube/i', 'type' => 'cms'],
                        
                        // Marketing & Tracking
                        'RD Station' => ['pattern' => '/rdstation|loader\.rd\.js/i', 'type' => 'marketing'],
                        'HubSpot' => ['pattern' => '/js\.hs-scripts\.com|hs-analytics/i', 'type' => 'marketing'],
                        'Facebook/Meta Pixel' => ['pattern' => '/connect\.facebook\.net|fbevents\.js|fbq\(/i', 'type' => 'pixel'],
                        'Google Analytics' => ['pattern' => '/google-analytics\.com|analytics\.js|gtag\(/i', 'type' => 'analytics'],
                        'Google Tag Manager' => ['pattern' => '/googletagmanager\.com|gtm\.js/i', 'type' => 'analytics'],
                        'Hotjar' => ['pattern' => '/static\.hotjar\.com|hj\(/i', 'type' => 'cro'],
                        
                        // CSS & Frameworks
                        'Tailwind CSS' => ['pattern' => '/tailwind|tailwindcss/i', 'type' => 'css'],
                        'Bootstrap' => ['pattern' => '/bootstrap/i', 'type' => 'css']
                    ];

                    foreach ($techRegex as $name => $info) {
                        if (preg_match($info['pattern'], $htmlBody) === 1) {
                            $technologies[] = $name;
                            
                            if ($info['type'] === 'cms') {
                                $detectedCms = true;
                                $detectedCmsName = $name;
                            }
                            if ($info['type'] === 'pixel' || $info['type'] === 'analytics' || $info['type'] === 'marketing') {
                                $detectedPixels = true;
                            }
                        }
                    }

                    // Identificar explicitamente qual CMS é utilizado caso exista
                    if ($detectedCms && $detectedCmsName) {
                        $technologies[] = "CMS: " . $detectedCmsName;
                    } else {
                        $technologies[] = "CMS: Customizado / Não Detectado";
                        $painPoints[] = [
                            'type' => 'info',
                            'title' => 'Nenhum CMS padrão conhecido',
                            'description' => 'O site parece utilizar um desenvolvimento sob medida (código customizado) ou um CMS de nicho, o que exige desenvolvedores especializados.'
                        ];
                    }

                    // Atribuir pontuações da tecnologia
                    if ($detectedPixels) {
                        $techScore += 15; // Ferramentas de Marketing (+15)
                    } else {
                        $painPoints[] = [
                            'type' => 'danger',
                            'title' => 'Sem pixel de anúncios',
                            'description' => 'Não encontramos Pixels de Anúncios (Facebook Pixel/Meta) ativos no site. O lead perde a chance de fazer remarketing estratégico para visitantes.'
                        ];
                    }

                    if ($detectedCms) {
                        $techScore += 15; // CMS profissional (+15)
                    }

                    // ----------------------------------------------------
                    // DETECÇÃO ADICIONAL DE DORES E OPORTUNIDADES
                    // ----------------------------------------------------

                    // 1. Mensuração de Tráfego (Google Analytics / GTM)
                    $hasAnalytics = false;
                    foreach ($technologies as $tech) {
                        if ($tech === 'Google Analytics' || $tech === 'Google Tag Manager') {
                            $hasAnalytics = true;
                            break;
                        }
                    }
                    if (!$hasAnalytics) {
                        $painPoints[] = [
                            'type' => 'danger',
                            'title' => 'Google Analytics ausente',
                            'description' => 'Não identificamos códigos do Google Analytics ou Tag Manager. O cliente está voando às cegas sem saber quantas visitas recebe ou de onde vêm.'
                        ];
                    }

                    // 2. Contato por WhatsApp (Gancho Comercial Fundamental para Apoio19!)
                    $hasWhatsApp = (preg_match('/wa\.me|api\.whatsapp\.com|whatsapp\.com\/send/i', $htmlBody) === 1);
                    if ($hasWhatsApp) {
                        $technologies[] = "Botão de WhatsApp";
                    } else {
                        $painPoints[] = [
                            'type' => 'warning',
                            'title' => 'Sem botão de WhatsApp',
                            'description' => 'Não encontramos links ou botões diretos de atendimento via WhatsApp no site. Isso pode reduzir as conversões imediatas de vendas em até 40%! (Excelente gancho para ofertar CRM + WhatsApp Apoio19).'
                        ];
                    }

                    // 3. Otimização SEO de Título
                    $hasTitle = (preg_match('/<title\b[^>]*>(.*?)<\/title>/i', $htmlBody, $titleMatches) === 1);
                    if (!$hasTitle || empty(trim($titleMatches[1] ?? ''))) {
                        $painPoints[] = [
                            'type' => 'danger',
                            'title' => 'Título HTML ausente ou vazio',
                            'description' => 'A tag de título HTML do site está ausente ou vazia. Isto prejudica gravemente a legibilidade e o rankeamento nos resultados do Google.'
                        ];
                    }

                    // 4. Otimização SEO de Meta Description
                    $hasMetaDescription = (preg_match('/<meta\s+name=["\']description["\']/i', $htmlBody) === 1);
                    if (!$hasMetaDescription) {
                        $painPoints[] = [
                            'type' => 'warning',
                            'title' => 'Sem Meta Description de SEO',
                            'description' => 'O site não possui uma meta descrição estruturada. O Google exibirá trechos aleatórios do texto da página na pesquisa, reduzindo cliques.'
                        ];
                    }

                } else {
                    // Site online respondeu com erro (ex: 500, 404)
                    $painPoints[] = [
                        'type' => 'danger',
                        'title' => 'Site inacessível / Erro no servidor',
                        'description' => "O site retornou um erro HTTP {$statusCode}. O cliente pode estar com problemas no servidor."
                    ];
                }
            } else {
                curl_close($ch);
                // Site offline/curl error
                $painPoints[] = [
                    'type' => 'danger',
                    'title' => 'Site offline ou domínio inexistente',
                    'description' => 'Não foi possível conectar ao site do lead. Pode estar fora do ar, o link está digitado incorretamente ou o domínio expirou.'
                ];
            }
        } else {
            $painPoints[] = [
                'type' => 'info',
                'title' => 'Sem site cadastrado',
                'description' => 'Cadastre o site do lead para auditar performance técnica e descobrir ferramentas de marketing instaladas.'
            ];
        }

        // ==========================================
        // CÁLCULO E PERSISTÊNCIA DO SCORE FINAL
        // ==========================================
        $finalScore = $profileScore + $performanceScore + $techScore;
        $finalScore = min(100, max(0, $finalScore)); // Capped between 0 and 100

        $updateData = [
            'score' => $finalScore,
            'site_audit_status' => !empty($url) ? 'Auditado' : 'Pendente',
            'site_performance' => json_encode($performance),
            'site_technologies' => json_encode($technologies),
            'site_pain_points' => json_encode($painPoints)
        ];

        // Atualizar Lead no Banco
        Lead::update($lead->id, $updateData);

        return [
            'score' => $finalScore,
            'site_audit_status' => $updateData['site_audit_status'],
            'site_performance' => $performance,
            'site_technologies' => $technologies,
            'site_pain_points' => $painPoints
        ];
    }
}
