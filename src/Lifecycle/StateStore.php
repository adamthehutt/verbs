<?php

namespace Thunk\Verbs\Lifecycle;

use Glhd\Bits\Snowflake;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Thunk\Verbs\Models\VerbEvent;
use Thunk\Verbs\Models\VerbSnapshot;
use Thunk\Verbs\State;
use UnexpectedValueException;

class StateStore
{
    protected array $stores = [];

    public function initialize(string $type, int|string $id = null): State
    {
        $state = new $type();
        $state->id = $id ?? Snowflake::make()->id();

        return $this->remember($state);
    }

    /** @param  class-string<State>  $type */
    public function load(int|string $id, string $type): State
    {
        if ($loaded = $this->stores[$type][(string) $id] ?? null) {
            return $loaded;
        }

        if ($snapshot = VerbSnapshot::find($id)) {
            if ($type !== $snapshot->type) {
                throw new UnexpectedValueException('State does not have a valid type.');
            }

            return $this->remember($snapshot->type::hydrate($snapshot->data));
        }

        return $type::initialize($id);
    }

    public static function getEventsForState(int|string $id, string $type): Collection
    {
        return VerbEvent::where('state_id', $id)
            ->where('state_type', $type)
            ->get();
    }

    public function writeLoaded(): bool
    {
        return $this->write(Arr::flatten($this->stores));
    }

    public function write(array $states): bool
    {
        return VerbSnapshot::insert(static::formatForWrite($states));
    }

    public function reset(): bool
    {
        VerbSnapshot::truncate();

        return true;
    }

    protected function remember(State $state): State
    {
        $this->stores[$state::class][(string) $state->id] = $state;

        return $state;
    }

    protected static function formatForWrite(array $states): array
    {
        return array_map(fn ($state) => [
            'type' => $state::class,
            'data' => json_encode(get_object_vars($state)),
            'created_at' => now(),
            'updated_at' => now(),
        ], $states);
    }
}
