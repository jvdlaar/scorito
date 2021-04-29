<?php

declare(strict_types = 1);

class ScoritoFormatter
{
    public static function formatQualities(array $rider): array
    {
        $map = [
            0 => 'Scorito GC',
            1 => 'Scorito Climb',
            2 => 'Scorito Time trial',
            3 => 'Scorito Sprint',
            4 => 'Scorito Punch',
            5 => 'Scorito Hill',
            6 => 'Scorito Cobbles',
        ];
        $qualities = $rider['Qualities'];
        unset($rider['Qualities']);

        $rider = array_merge($rider, array_fill_keys($map, 0));

        foreach ($qualities as $quality) {
            $rider[$map[$quality['Type']]] = $quality['Value'];
        }

        return $rider;
    }
}