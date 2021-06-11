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

    public static function formatType(array $rider): array
    {
        $mapping = [
            1 => 'GC',
            2 => 'Climber',
            3 => 'TT',
            4 => 'Sprinter',
            5 => 'Attacker',
            6 => 'Support',
        ];

        $rider['Type'] = $mapping[$rider['Type']];

        return $rider;
    }

    public static function formatTeam(array $rider, array $teams): array
    {
        foreach ($teams as $team) {
            if ($team['Id'] === $rider['TeamId']) {
                $rider['Team'] = $team['Name'];
            }
        }

        return $rider;
    }

    public static function filterColumns(array $rider): array
    {
        unset($rider['EventRiderId']);
        unset($rider['Status']);
        unset($rider['TeamId']);
        unset($rider['RiderId']);

        return $rider;
    }
}