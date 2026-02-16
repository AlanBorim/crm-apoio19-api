<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Proposal;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\Contact;
use Apoio19\Crm\Models\Company;
use Apoio19\Crm\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class PdfService
{
    private string $templatePath;
    private string $storagePath;

    public function __construct()
    {
        // Define paths
        $this->templatePath = __DIR__ . '/../../templates/proposal_template.html';
        $this->storagePath = __DIR__ . '/../../storage/proposals';

        // Create storage directory if it doesn't exist
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Generates a PDF for a given proposal using DomPDF.
     *
     * @param int $proposalId
     * @return string|false Path to the generated PDF or false on failure.
     */
    public function generateProposalPdf(int $proposalId): string|false
    {
        try {
            $proposal = Proposal::findById($proposalId);
            if (!$proposal) {
                throw new Exception("Proposta não encontrada.");
            }

            $items = Proposal::getItems($proposalId);

            // Fetch related data
            $clientData = $this->getClientData($proposal);
            $responsibleUser = $proposal->responsavel_id ? User::findById($proposal->responsavel_id) : null;

            // Prepare data for the template
            $templateData = [
                '{{proposal_id}}' => $proposal->id,
                '{{proposal_title}}' => htmlspecialchars($proposal->titulo ?? ''),
                '{{proposal_date}}' => date("d/m/Y"),
                '{{proposal_validity}}' => $proposal->data_validade ? date("d/m/Y", strtotime($proposal->data_validade)) : 'N/A',
                '{{proposal_responsible}}' => $responsibleUser ? htmlspecialchars($responsibleUser->name) : 'N/A',
                '{{client_company_name}}' => htmlspecialchars($clientData['company_name'] ?? 'N/A'),
                '{{client_contact_name}}' => htmlspecialchars($clientData['contact_name'] ?? 'N/A'),
                '{{client_email}}' => htmlspecialchars($clientData['email'] ?? 'N/A'),
                '{{client_phone}}' => htmlspecialchars($clientData['phone'] ?? 'N/A'),
                '{{proposal_description}}' => nl2br(htmlspecialchars($proposal->descricao ?? '')),
                '{{proposal_conditions}}' => nl2br(htmlspecialchars($proposal->condicoes ?? '')),
                '{{proposal_total_value_formatted}}' => number_format($proposal->valor_total, 2, ',', '.'),
            ];

            // Prepare items HTML block
            $itemsHtml = '';
            foreach ($items as $item) {
                // Determine keys based on likely DB columns (description/quantity vs descricao/quantidade)
                $desc = $item['description'] ?? $item['descricao'] ?? '';
                $qtd = $item['quantity'] ?? $item['quantidade'] ?? 0;
                $unit = $item['unit_price'] ?? $item['valor_unitario'] ?? 0;
                $total = $item['total_price'] ?? $item['valor_total'] ?? 0;

                $itemsHtml .= '<tr>';
                $itemsHtml .= '    <td>' . htmlspecialchars($desc) . '</td>';
                $itemsHtml .= '    <td>' . number_format((float)$qtd, 2, ',', '.') . '</td>';
                $itemsHtml .= '    <td>R$ ' . number_format((float)$unit, 2, ',', '.') . '</td>';
                $itemsHtml .= '    <td>R$ ' . number_format((float)$total, 2, ',', '.') . '</td>';
                $itemsHtml .= '</tr>';
            }
            $templateData['{{#each proposal_items}}...{{/each}}'] = $itemsHtml;

            // Read the template
            // Since we might not have a dedicated template engine, we check for file or use default content
            if (file_exists($this->templatePath)) {
                $htmlContent = file_get_contents($this->templatePath);
            } else {
                // Fallback basic template if file missing
                $htmlContent = $this->getDefaultTemplate();
            }

            if ($htmlContent === false) {
                throw new Exception("Não foi possível ler o template da proposta.");
            }

            // Replace placeholders
            $renderedHtml = str_replace(array_keys($templateData), array_values($templateData), $htmlContent);
            // Cleanup remaining loop markers if any
            $renderedHtml = str_replace(['{{#each proposal_items}}', '{{/each}}'], '', $renderedHtml);

            // Configure Dompdf
            $options = new Options();
            $options->set('isRemoteEnabled', true); // Allow remote images
            $options->set('isHtml5ParserEnabled', true);
            $dompdf = new Dompdf($options);

            $dompdf->loadHtml($renderedHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Save PDF
            $pdfFileName = 'proposta_' . $proposal->id . '_' . time() . '.pdf';
            $pdfPath = $this->storagePath . '/' . $pdfFileName;

            if (file_put_contents($pdfPath, $dompdf->output()) === false) {
                throw new Exception("Não foi possível salvar o arquivo PDF.");
            }

            // Update proposal record with PDF path
            Proposal::update($proposalId, ['pdf_path' => $pdfPath], [], null);

            return $pdfPath;
        } catch (Exception $e) {
            error_log("Erro na geração do PDF da proposta ID {$proposalId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Helper to get client data based on proposal associations.
     */
    private function getClientData(Proposal $proposal): array
    {
        $data = [
            'company_name' => null,
            'contact_name' => null,
            'email' => null,
            'phone' => null,
        ];

        // Ensure models exist or handle missing ones carefully
        // Assuming Contact/Company classes exist and have 'name' property based on CRM SQL

        if ($proposal->contato_id && class_exists(Contact::class)) {
            $contact = Contact::findById($proposal->contato_id);
            if ($contact) {
                $data['contact_name'] = $contact->name; // Changed from nome to name
                $data['email'] = $contact->email;
                $data['phone'] = $contact->phone; // Changed from telefone to phone? database says 'phone' in contacts table
                if ($contact->company_id && class_exists(Company::class)) {
                    $company = Company::findById($contact->company_id);
                    if ($company) {
                        $data['company_name'] = $company->name;
                    }
                }
            }
        } elseif ($proposal->empresa_id && class_exists(Company::class)) {
            $company = Company::findById($proposal->empresa_id);
            if ($company) {
                $data['company_name'] = $company->name;
                $data['email'] = $company->email;
                $data['phone'] = $company->phone;
            }
        } elseif ($proposal->lead_id) {
            $lead = Lead::findById($proposal->lead_id);
            if ($lead) {
                $data['contact_name'] = $lead->name;
                $data['company_name'] = $lead->company;
                $data['email'] = $lead->email;
                $data['phone'] = $lead->phone;
            }
        }

        return $data;
    }

    private function getDefaultTemplate(): string
    {
        return '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: DejaVu Sans, sans-serif; }
                .header { text-align: center; margin-bottom: 20px; }
                .details { margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>{{proposal_title}}</h1>
                <p>Proposta #{{proposal_id}}</p>
            </div>
            <div class="details">
                <p><strong>Cliente:</strong> {{client_contact_name}} - {{client_company_name}}</p>
                <p><strong>Email:</strong> {{client_email}}</p>
                <p><strong>Data:</strong> {{proposal_date}}</p>
                <p><strong>Validade:</strong> {{proposal_validity}}</p>
                <p><strong>Responsável:</strong> {{proposal_responsible}}</p>
            </div>
            
            <div class="description">
                <h3>Descrição</h3>
                <p>{{proposal_description}}</p>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qtd</th>
                        <th>Valor Unit.</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    {{#each proposal_items}}...{{/each}}
                </tbody>
            </table>

            <div style="text-align: right; margin-top: 20px;">
                <h3>Total: R$ {{proposal_total_value_formatted}}</h3>
            </div>

            <div class="conditions">
                <h3>Condições</h3>
                <p>{{proposal_conditions}}</p>
            </div>
        </body>
        </html>
        ';
    }
}
