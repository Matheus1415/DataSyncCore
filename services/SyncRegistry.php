<?php

namespace App\Services\Sync;

use App\Contracts\Syncable;
use InvalidArgumentException;

/*
|----------------------------------------------------------------------
| Gerenciador central de entidades sincronizáveis do ecossistema
|----------------------------------------------------------------------
| Este registro mapeia aliases amigáveis (ex: 'servicos') para suas respectivas
| classes de implementação, permitindo a resolução dinâmica via Service Container.
|-------------- COMO ADICIONAR NOVAS ENTIDADES --------------
| 1. Crie a classe em App\Services\Sync\Entities implementando Syncable.
| 2. Registre o alias e a classe no array $entities abaixo.
|----------------------------------------------------------------------
*/
class SyncRegistry
{
    /**
     * Mapa de entidades registradas no sistema de sincronização.
     * * @var array<string, class-string<Syncable>>
     */
    protected array $entities = [
        'vendas' => \App\Services\Sync\Entities\Vendas::class,
        'servicos' => \App\Services\Sync\Entities\Servicos::class,
    ];

    /**
     * Resolve e instancia uma entidade de sincronização pelo seu alias.
     *
     * @param string $entity Alias da entidade (ex: 'servicos').
     * @return Syncable
     * @throws InvalidArgumentException Caso a entidade não esteja mapeada.
     */
    public function resolve(string $entity): Syncable
    {
        if (!$this->has($entity)) {
            throw new InvalidArgumentException("A entidade de sincronização [{$entity}] não foi registrada no SyncRegistry.");
        }

        return app($this->entities[$entity]);
    }

    /**
     * Retorna a lista de todos os aliases de entidades disponíveis.
     *
     * @return array<int, string>
     */
    public function all(): array
    {
        return array_keys($this->entities);
    }

    /**
     * Verifica se um determinado alias de entidade está registrado.
     *
     * @param string $entity
     * @return bool
     */
    public function has(string $entity): bool
    {
        return isset($this->entities[$entity]);
    }
}