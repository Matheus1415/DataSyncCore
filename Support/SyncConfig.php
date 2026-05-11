<?php

namespace App\Support\Sync;

class SyncConfig
{
    /*
    |----------------------------------------------------------------------
    | Campos Ignorados na Comparação e Geração de Hash
    |----------------------------------------------------------------------
    | Estes campos são desconsiderados durante:
    |
    | - comparação de diferenças (DiffService)
    | - geração de hash de sincronização
    |
    | O objetivo é evitar falsos conflitos causados por:
    | - IDs internos diferentes entre sistemas
    | - timestamps automáticos
    | - estruturas auxiliares irrelevantes para negócio
    | - relacionamentos voláteis ou temporários
    |
    | Exemplo:
    | O registro pode estar sincronizado corretamente mesmo que:
    | - o "id" local seja diferente do remoto
    | - o "updated_at" tenha timestamps distintos
    | - listas auxiliares estejam vazias em um dos lados
    |
    | Esses campos NÃO representam divergência funcional.
    |----------------------------------------------------------------------
    */
    public static function ignoredFields(): array
    {
        return [
            'id',
            'external_id',
            'created_at',
            'updated_at',

            // Estruturas auxiliares de VendaDTO
            'baixas',
            'anexo',
        ];
    }
}