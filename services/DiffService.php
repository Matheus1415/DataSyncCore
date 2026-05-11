<?php

namespace App\Services\Sync;
use App\Support\Sync\SyncConfig;

class DiffService
{
    /*
    |----------------------------------------------------------------------
    | Serviço de Diferenciação de Dados (DiffService)
    |----------------------------------------------------------------------
    | Responsável por comparar dados locais e remotos já previamente
    | alinhados (merge) pelo SyncEngine.
    |
    | Trabalha com:
    | - key   → identidade lógica (ex: código)
    | - meta  → ids técnicos (local_id, external_id)
    | - data  → conteúdo (local vs remote)
    |
    | Utiliza hash para performance e comparação campo a campo para detalhe.
    |----------------------------------------------------------------------
    */

    private array $toIgnore;

    public function __construct()
    {
        $this->toIgnore = SyncConfig::ignoredFields();
    }

    /**
     * Compara coleção já mesclada (mergeWithMeta)
     *
     * @param array $items
     * @return array
     */
    public function compare(array $items): array
    {
        $diffs = [];

        foreach ($items as $item) {
            $key    = $item['key'];
            $meta   = $item['meta'] ?? [];
            $local  = $item['local'] ?? null;
            $remote = $item['remote'] ?? null;

            // Caso 1: Existe apenas no remoto
            if (!$local && $remote) {
                $diffs[] = [
                    'type' => 'missing_local',
                    'key'  => $key,
                    'meta' => $meta,
                    'data' => [
                        'local'  => null,
                        'remote' => $remote,
                    ],
                ];
                continue;
            }

            // Caso 2: Existe apenas no local
            if ($local && !$remote) {
                $diffs[] = [
                    'type' => 'missing_remote',
                    'key'  => $key,
                    'meta' => $meta,
                    'data' => [
                        'local'  => $local,
                        'remote' => null,
                    ],
                ];
                continue;
            }

            // Caso 3: Existe em ambos → comparar
            if ($local && $remote) {

                if ($this->isEqual($local, $remote)) {
                    continue;
                }

                $diffs[] = [
                    'type' => 'conflict',
                    'key'  => $key,
                    'meta' => $meta,
                    'data' => [
                        'local'  => $local,
                        'remote' => $remote,
                    ],
                    'changes' => $this->diffFields($local, $remote),
                ];
            }
        }

        return $diffs;
    }

    /**
     * Compara via hash (rápido)
     */
    protected function isEqual(array $local, array $remote): bool
    {
        $localNorm  = $this->normalize($local);
        $remoteNorm = $this->normalize($remote);

        return md5(json_encode($localNorm)) === md5(json_encode($remoteNorm));
    }

    /**
     * Retorna diferenças campo a campo
     */
    protected function diffFields(array $local, array $remote): array
    {
        $fields = [];

        $localNorm  = $this->normalize($local);
        $remoteNorm = $this->normalize($remote);

        $allFields = array_unique(array_merge(
            array_keys($localNorm),
            array_keys($remoteNorm)
        ));

        foreach ($allFields as $field) {
            $localValue  = $localNorm[$field] ?? null;
            $remoteValue = $remoteNorm[$field] ?? null;

            if ($localValue !== $remoteValue) {
                $fields[$field] = [
                    'local'  => $localValue,
                    'remote' => $remoteValue,
                ];
            }
        }

        return $fields;
    }

    /**
     * Normaliza dados antes da comparação de forma recursiva
     */
    protected function normalize(array $data): array
    {
        // Remove campos irrelevantes
        foreach ($this->toIgnore as $field) {
            unset($data[$field]);
        }

        // Ordena as chaves para evitar falso positivo por ordem diferente
        ksort($data);

        // Normaliza os valores (Recursivo para lidar com cliente, itens e parcelas)
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->normalize($value);
            } elseif (is_null($value)) {
                $data[$key] = '';
            } elseif (is_bool($value)) {
                $data[$key] = $value ? '1' : '0';
            } else {
                $data[$key] = (string) $value;
            }
        }

        return $data;
    }
}