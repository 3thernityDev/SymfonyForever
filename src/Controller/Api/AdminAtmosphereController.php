<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Game\WorldAtmosphere;
use App\Service\Game\WorldAtmosphereService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AdminAtmosphereController extends AbstractController
{
    public const string SESSION_KEY = 'iddad_admin_user_id';

    public static function isSessionAdmin(Request $request, ?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        if (!$request->hasSession()) {
            return false;
        }

        return (int) $request->getSession()->get(self::SESSION_KEY, 0) === (int) $user->getId();
    }

    public static function grantSessionAdmin(Request $request, User $user): void
    {
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_KEY, (int) $user->getId());
        }
    }

    #[Route('/api/admin/atmosphere', name: 'api_admin_atmosphere', methods: ['POST'])]
    public function update(Request $request, WorldAtmosphereService $atmosphereService): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!self::isSessionAdmin($request, $user)) {
            return $this->json([
                'ok' => false,
                'error' => 'Accès admin requis (tapez /iddad dans le chat).',
            ], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['ok' => false, 'error' => 'Payload JSON invalide.'], 400);
        }

        if (($data['action'] ?? null) === 'reset') {
            $atmosphere = $atmosphereService->resetAll();

            return $this->json([
                'ok' => true,
                'atmosphere' => $atmosphere,
                'notice' => '♻️ Cycle jour/nuit et météo remis en mode automatique pour tous les joueurs.',
            ]);
        }

        $hasWeather = array_key_exists('weather', $data);
        $weather = null;
        if ($hasWeather) {
            $rawWeather = $data['weather'];
            if ($rawWeather === null || (is_string($rawWeather) && strtoupper(trim($rawWeather)) === 'AUTO')) {
                $weather = null;
            } elseif (is_string($rawWeather) && WorldAtmosphere::isValidWeather(strtoupper(trim($rawWeather)))) {
                $weather = strtoupper(trim($rawWeather));
            } else {
                return $this->json([
                    'ok' => false,
                    'error' => 'Météo invalide (CLEAR, RAIN, STORM, SANDSTORM ou AUTO).',
                ], 422);
            }
        }

        $resetTime = isset($data['timeMode']) && is_string($data['timeMode']) && strtoupper(trim($data['timeMode'])) === 'AUTO';

        $hour = null;
        if (array_key_exists('timeOfDay', $data) && $data['timeOfDay'] !== null) {
            $rawHour = $data['timeOfDay'];
            if (!is_int($rawHour) && !is_float($rawHour) && !is_numeric($rawHour)) {
                return $this->json([
                    'ok' => false,
                    'error' => 'Heure invalide (valeur attendue entre 0 et 23.99).',
                ], 422);
            }
            $parsedHour = (float) $rawHour;
            if (!is_finite($parsedHour) || $parsedHour < 0.0 || $parsedHour >= 24.0) {
                return $this->json([
                    'ok' => false,
                    'error' => 'Heure invalide (valeur attendue entre 0 et 23.99).',
                ], 422);
            }
            $hour = $parsedHour;
        }

        $freeze = null;
        if (array_key_exists('freezeTime', $data) && $data['freezeTime'] !== null) {
            if (!is_bool($data['freezeTime'])) {
                return $this->json(['ok' => false, 'error' => 'Paramètre freezeTime invalide.'], 422);
            }
            $freeze = $data['freezeTime'];
        }

        if (!$hasWeather && !$resetTime && $hour === null && $freeze === null) {
            return $this->json(['ok' => false, 'error' => 'Aucun paramètre atmosphérique fourni.'], 422);
        }

        $atmosphere = $atmosphereService->applyChanges($weather, $hasWeather, $hour, $resetTime, $freeze);

        $totalMins = (int) floor($atmosphere['timeOfDay'] * 60.0);
        $hh = str_pad((string) (intdiv($totalMins, 60) % 24), 2, '0', STR_PAD_LEFT);
        $mm = str_pad((string) ($totalMins % 60), 2, '0', STR_PAD_LEFT);
        $freezeTag = $atmosphere['isTimeFrozen'] ? ' (figée)' : '';
        $weatherTag = $atmosphere['isWeatherOverridden'] ? '' : ' (auto)';

        return $this->json([
            'ok' => true,
            'atmosphere' => $atmosphere,
            'notice' => sprintf(
                '🌍 Atmosphère commune : %s:%s%s · %s%s.',
                $hh,
                $mm,
                $freezeTag,
                $atmosphere['weatherLabel'],
                $weatherTag,
            ),
        ]);
    }
}
