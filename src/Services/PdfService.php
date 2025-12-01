<?php

namespace Apoio19\Crm\Services;

use Apoio19\Crm\Models\Proposal;
use Apoio19\Crm\Models\Lead;
use Apoio19\Crm\Models\Contact; // Assuming Contact model exists or will be created
use Apoio19\Crm\Models\Company; // Assuming Company model exists or will be created
use Apoio19\Crm\Models\User;
use Exception;

class PdfService
{
    private string $templatePath;
    private string $storagePath;

    public function __construct()
    {
        // Define paths - these should ideally be configurable
        $this->templatePath = __DIR__ . '/../../templates/proposal_template.html';
        $this->storagePath = __DIR__ . '/../../storage/proposals'; // Ensure this directory exists and is writable

        // Create storage directory if it doesn't exist
        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0775, true);
        }
    }

    /**
     * Generates a PDF for a given proposal.
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

            // Fetch related data (client, responsible user)
            $clientData = $this->getClientData($proposal);
            $responsibleUser = $proposal->responsavel_id ? User::findById($proposal->responsavel_id) : null;

            // Prepare data for the template
            $templateData = [
                '{{proposal_id}}' => $proposal->id,
                '{{proposal_title}}' => htmlspecialchars($proposal->titulo ??
                    ''),
                '{{proposal_date}}' => date("d/m/Y"), // Or use proposal creation date?
                '{{proposal_validity}}' => $proposal->data_validade ? date("d/m/Y", strtotime($proposal->data_validade)) : 'N/A',
                '{{proposal_responsible}}' => $responsibleUser ? htmlspecialchars($responsibleUser->nome) : 'N/A',
                '{{client_company_name}}' => htmlspecialchars($clientData['company_name
'] ??
                    'N/A'),
                '{{client_contact_name}}' => htmlspecialchars($clientData['contact_name
'] ??
                    'N/A'),
                '{{client_email}}' => htmlspecialchars($clientData['email
'] ??
                    'N/A'),
                '{{client_phone}}' => htmlspecialchars($clientData['phone
'] ??
                    'N/A'),
                '{{proposal_description}}' => nl2br(htmlspecialchars($proposal->descricao ??
                    '')), // Use nl2br for line breaks
                '{{proposal_conditions}}' => nl2br(htmlspecialchars($proposal->condicoes ??
                    '')), // Use nl2br for line breaks
                '{{proposal_total_value_formatted}}' => number_format($proposal->valor_total, 2, ',', '.'),
            ];

            // Prepare items HTML block
            $itemsHtml =
                '';
            foreach ($items as $item) {
                $itemsHtml .=
                    '<tr>
';
                $itemsHtml .=
                    '    <td>
' . htmlspecialchars($item['descricao
']) .
                    '</td>
';
                $itemsHtml .=
                    '    <td>
' . number_format((float)$item['quantidade
'], 2, ',', '.') .
                    '</td>
';
                $itemsHtml .=
                    '    <td>
' . number_format((float)$item['valor_unitario
'], 2, ',', '.') .
                    '</td>
';
                $itemsHtml .=
                    '    <td>
' . number_format((float)$item['valor_total_item
'], 2, ',', '.') .
                    '</td>
';
                $itemsHtml .=
                    '</tr>
';
            }
            $templateData['{{#each proposal_items}}...{{/each}}
'] = $itemsHtml; // Simple replacement for the loop block

            // Read and render the template
            $htmlContent = file_get_contents($this->templatePath);
            if ($htmlContent === false) {
                throw new Exception("Não foi possível ler o template da proposta.");
            }

            // Replace placeholders - Note: This simple replacement won't handle loops like {{#each}}
            // We manually replaced the items block above.
            $renderedHtml = str_replace(array_keys($templateData), array_values($templateData), $htmlContent);
            // Remove the placeholder loop markers if they remain
            $renderedHtml = str_replace('{{#each proposal_items}}', '', $renderedHtml);
            $renderedHtml = str_replace('{{/each}}', '', $renderedHtml);


            // Define file paths
            $tempHtmlPath = tempnam(sys_get_temp_dir(), 'proposal_') . '.html';
            $pdfFileName = 'proposta_' . $proposal->id . '_' . time() . '.pdf';
            $pdfPath = $this->storagePath . '/' . $pdfFileName;

            if (file_put_contents($tempHtmlPath, $renderedHtml) === false) {
                throw new Exception("Não foi possível salvar o arquivo HTML temporário.");
            }

            // Execute WeasyPrint via shell
            // Ensure weasyprint command is available in the environment's PATH
            $command = sprintf(
                'weasyprint %s %s',
                escapeshellarg($tempHtmlPath),
                escapeshellarg($pdfPath)
            );

            // Execute the command
            $output = null;
            $return_var = null;
            exec($command . ' 2>&1', $output, $return_var);

            // Clean up temporary HTML file
            unlink($tempHtmlPath);

            // Check for errors
            if ($return_var !== 0) {
                $errorOutput = implode("\n", $output);
                error_log("Erro ao gerar PDF com WeasyPrint (Proposal ID: {$proposalId}): {$errorOutput}");
                throw new Exception("Falha ao gerar o PDF da proposta. Detalhes: " . $errorOutput);
            }

            // Update proposal record with PDF path
            Proposal::update($proposalId, ['pdf_path' => $pdfPath], [], null); // Pass empty items and null user ID as we only update pdf_path

            return $pdfPath;
        } catch (Exception $e) {
            error_log("Erro na geração do PDF da proposta ID {$proposalId}: " . $e->getMessage());
            // Clean up temp file if it exists and an error occurred before unlink
            if (isset($tempHtmlPath) && file_exists($tempHtmlPath)) {
                unlink($tempHtmlPath);
            }
            return false;
        }
    }

    /**
     * Helper to get client data based on proposal associations.
     *
     * @param Proposal $proposal
     * @return array
     */
    private function getClientData(Proposal $proposal): array
    {
        $data = [
            'company_name' => null,
            'contact_name' => null,
            'email' => null,
            'phone' => null,
        ];

        // Prioritize Contact, then Company, then Lead
        if ($proposal->contato_id && ($contact = Contact::findById($proposal->contato_id))) {
            $data['contact_name'] = $contact->nome;
            $data['email'] = $contact->email;
            $data['phone'] = $contact->telefone;
            if ($contact->empresa_id && ($company = Company::findById($contact->empresa_id))) {
                $data['company_name'] = $company->nome;
            }
        } elseif ($proposal->empresa_id && ($company = Company::findById($proposal->empresa_id))) {
            $data['company_name'] = $company->nome;
            $data['email'] = $company->email;
            $data['phone'] = $company->telefone;
            // Maybe find a primary contact for the company?
        } elseif ($proposal->lead_id && ($lead = Lead::findById($proposal->lead_id))) {
            $data['contact_name'] = $lead->name; // Lead name as contact
            $data['company_name'] = $lead->company;
            $data['email'] = $lead->email;
            $data['phone'] = $lead->phone;
        }

        return $data;
    }
}
