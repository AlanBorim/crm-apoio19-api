<?php

namespace Apoio19\Crm\Utils;

/**
 * Data Transformer Utility
 * Converts between Portuguese (frontend) and English (database) field names
 */
class DataTransformer
{
    /**
     * Contact field mappings: Portuguese => English
     */
    private static array $contactFieldMap = [
        'nome' => 'name',
        'empresa_id' => 'company_id',
        'cargo' => 'position',
        'telefone' => 'phone',
        'notas_privadas' => 'notes',
        'criado_em' => 'created_at',
        'atualizado_em' => 'updated_at'
    ];

    /**
     * Company field mappings: Portuguese => English
     */
    private static array $companyFieldMap = [
        'nome' => 'name',
        'endereco' => 'address',
        'telefone' => 'phone',
        'criado_em' => 'created_at',
        'atualizado_em' => 'updated_at'
    ];

    /**
     * Proposal field mappings: Portuguese => English
     */
    private static array $proposalFieldMap = [
        'contato_id' => 'contact_id',
        'empresa_id' => 'company_id',
        'criado_em' => 'created_at',
        'atualizado_em' => 'updated_at'
    ];

    /**
     * Transform contact data from Portuguese to English
     *
     * @param array $data Data with Portuguese field names
     * @return array Data with English field names
     */
    public static function transformContactToEnglish(array $data): array
    {
        return self::transformFields($data, self::$contactFieldMap);
    }

    /**
     * Transform contact data from English to Portuguese
     *
     * @param array $data Data with English field names
     * @return array Data with Portuguese field names
     */
    public static function transformContactToPortuguese(array $data): array
    {
        return self::transformFields($data, array_flip(self::$contactFieldMap));
    }

    /**
     * Transform company data from Portuguese to English
     *
     * @param array $data Data with Portuguese field names
     * @return array Data with English field names
     */
    public static function transformCompanyToEnglish(array $data): array
    {
        return self::transformFields($data, self::$companyFieldMap);
    }

    /**
     * Transform company data from English to Portuguese
     *
     * @param array $data Data with English field names
     * @return array Data with Portuguese field names
     */
    public static function transformCompanyToPortuguese(array $data): array
    {
        return self::transformFields($data, array_flip(self::$companyFieldMap));
    }

    /**
     * Transform proposal data from Portuguese to English
     *
     * @param array $data Data with Portuguese field names
     * @return array Data with English field names
     */
    public static function transformProposalToEnglish(array $data): array
    {
        return self::transformFields($data, self::$proposalFieldMap);
    }

    /**
     * Transform proposal data from English to Portuguese
     *
     * @param array $data Data with English field names
     * @return array Data with Portuguese field names
     */
    public static function transformProposalToPortuguese(array $data): array
    {
        return self::transformFields($data, array_flip(self::$proposalFieldMap));
    }

    /**
     * Generic field transformation
     *
     * @param array $data Source data
     * @param array $fieldMap Field mapping (source => target)
     * @return array Transformed data
     */
    private static function transformFields(array $data, array $fieldMap): array
    {
        $transformed = [];

        foreach ($data as $key => $value) {
            // If field has a mapping, use the mapped name
            if (isset($fieldMap[$key])) {
                $transformed[$fieldMap[$key]] = $value;
            } else {
                // Keep original field name if no mapping exists
                $transformed[$key] = $value;
            }
        }

        return $transformed;
    }

    /**
     * Transform object properties from English to Portuguese
     *
     * @param object $object Object with English property names
     * @param string $type Type of object ('contact', 'company', 'proposal')
     * @return array Array with Portuguese field names
     */
    public static function transformObjectToPortuguese(object $object, string $type): array
    {
        $data = (array)$object;

        switch ($type) {
            case 'contact':
                return self::transformContactToPortuguese($data);
            case 'company':
                return self::transformCompanyToPortuguese($data);
            case 'proposal':
                return self::transformProposalToPortuguese($data);
            default:
                return $data;
        }
    }
}
