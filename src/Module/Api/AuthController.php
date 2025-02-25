<?php

declare(strict_types=1);

namespace App\Module\Api;

use App\Attributes\Transaction;
use App\DTO\UserDTO;
use App\Entity\User;
use App\Entity\UserSecret;
use App\Enum\ApiTokenType;
use App\Enum\ErrorCode;
use App\Service\EncryptionService;
use App\Service\JwtAuthService;
use Brick\Math\BigInteger;
use Brick\Math\Exception\NumberFormatException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Lyrasoft\Luna\Auth\SRP\SRPService;
use Lyrasoft\Luna\User\UserService;
use Psr\Container\ContainerExceptionInterface;
use Windwalker\Core\Application\AppContext;
use Windwalker\Core\Attributes\Controller;
use Windwalker\Core\Http\RequestAssert;
use Windwalker\Crypt\Symmetric\CipherInterface;
use Windwalker\ORM\ORM;
use Windwalker\SRP\Exception\InvalidSessionProofException;

use Windwalker\SRP\Step\PasswordFile;

use Windwalker\SRP\Step\ProofResult;

use function Windwalker\chronos;
use function Windwalker\Query\uuid2bin;
use function Windwalker\uid;

#[Controller]
class AuthController
{
    public function challenge(
        AppContext $app,
        ORM $orm,
        SRPService $srpService,
    ): ?array {
        $email = $app->input('email');

        RequestAssert::assert($email, 'No email');

        $sessId = uid();
        $user = $orm->findOne(User::class, compact('email'));

        if (!$user) {
            ErrorCode::INVALID_CREDENTIALS->throw();
        }

        $userSecret = $orm->mustFindOne(UserSecret::class, ['user_id' => uuid2bin($user->getId())]);

        $password = $user->getPassword();

        if (!$srpService::isValidSRPHash($password)) {
            throw new \RuntimeException('User credential was corrupted.');
        }

        $pf = $srpService::decodePasswordVerifier($password);

        // Run SRP step
        $e = $srpService->step1($email, $pf->salt, $pf->verifier);

        // Detect if first login or secret updates
        $firstLogin = (!$userSecret->getSecret() || !$userSecret->getMaster());

        $loginToken = JWT::encode(
            [
                'exp' => time() + 10000,
                'sess' => $sessId,
                'b' => $e->secret->toBase(16),
                'B' => $e->public->toBase(16),
                'updateSecrets' => $firstLogin,
            ],
            $userSecret->getDecodedServerSecret(),
            'HS512'
        );

        $user->setLoginToken($loginToken);

        $orm->updateOne($user);

        $sess = JWT::encode(
            [
                'exp' => time() + 10000,
                'sess' => $sessId,
            ],
            $userSecret->getDecodedServerSecret(),
            'HS512'
        );

        return [
            'salt' => $pf->salt->toBase(16),
            'B' => $e->public->toBase(16),
            'sess' => $sess,
            'firstLogin' => $firstLogin,
        ];
    }

    #[Transaction]
    public function authenticate(
        AppContext $app,
        ORM $orm,
        JwtAuthService $jwtAuthService,
        CipherInterface $cipher,
    ): array {
        [
            $email,
            $A,
            $M1,
            $sess,
            $encSecret,
            $encMaster,
        ] = $app->input(
            'email',
            'A',
            'M1',
            'sess',
            'encSecret',
            'encMaster',
        )->values();

        RequestAssert::assert($email, 'No email');
        RequestAssert::assert($A, 'Invalid credentials');
        RequestAssert::assert($M1, 'Invalid credentials');

        if (!str_contains($email, '@')) {
            // Todo: Use email filters
            throw new \RuntimeException('Invalid Email format');
        }

        $user = $orm->findOne(User::class, compact('email'));

        if (!$user) {
            ErrorCode::INVALID_CREDENTIALS->throw();
        }

        [$result, $loginPayload, $userSecret] = $app->call(
            $this->srpValidate(...),
            compact(
                'user',
                'A',
                'M1',
                'sess'
            )
        );

        try {
            // Save enc secrets
            if ($loginPayload->updateSecrets && $encSecret && $encMaster) {
                $encSecret = $cipher->decrypt($encSecret, $result->preMasterSecret->toBase(10));
                $encMaster = $cipher->decrypt($encMaster, $result->preMasterSecret->toBase(10));

                $userSecret->setSecret($encSecret->get());
                $userSecret->setMaster($encMaster->get());

                $orm->updateOne($userSecret);
            }

            $user->setLastLogin('now');

            $orm->updateOne($user);

            // Create JWT Token
            $accessToken = $jwtAuthService->createAccessToken($user, $userSecret);
            $refreshToken = $jwtAuthService->createRefreshToken($user, $userSecret);

            $user = UserDTO::wrap($user);

            $key = $result->key->toBase(16);
            $proof = $result->proof->toBase(16);

            $encSecret = $cipher->encrypt($userSecret->getSecret(), $result->preMasterSecret->toBase(10));
            $encMaster = $cipher->encrypt($userSecret->getMaster(), $result->preMasterSecret->toBase(10));

            return compact(
                'key',
                'proof',
                'accessToken',
                'refreshToken',
                'user',
                'encSecret',
                'encMaster',
            );
        } catch (InvalidSessionProofException) {
            ErrorCode::INVALID_CREDENTIALS->throw();
        }
    }

    #[Transaction]
    public function register(
        AppContext $app,
        ORM $orm,
        UserService $userService,
        CipherInterface $cipher
    ): array {
        [
            $email,
            $salt,
            $verifier,
        ] = $app->input('email', 'salt', 'verifier')->values();

        RequestAssert::assert($email, 'No Email');

        if (!str_contains($email, '@')) {
            // Todo: Use email filters
            throw new \RuntimeException('Invalid Email format');
        }

        $verifier = BigInteger::fromBase($verifier, 16);
        $salt = BigInteger::fromBase($salt, 16);

        $exists = $orm->findOne(User::class, compact('email'));

        if ($exists) {
            ErrorCode::USER_EMAIL_EXISTS->throw();
        }

        $password = SRPService::encodePasswordVerifier($salt, $verifier);

        /** @var User $user */
        $user = $userService->createUserEntity();
        $user->setEmail($email);
        $user->setPassword($password);
        $user->setRegistered('now');
        $user->setEnabled(true);
        $user->setVerified(true);

        $user = $orm->createOne($user);

        $user = UserDTO::wrap($user);

        return compact('user');
    }

    public function refreshToken(
        AppContext $app,
        JwtAuthService $jwtAuthService
    ): array {
        $authHeader = $app->getHeader('Authorization');

        $payload = $jwtAuthService->extractAccessTokenFromHeader($authHeader, $user, ApiTokenType::REFRESH);

        $exp = $payload->getExp();

        if ($exp < time()) {
            ErrorCode::REFRESH_TOKEN_EXPIRED->throw();
        }

        $userSecret = $user->getSecretEntity(true);

        $accessToken = $jwtAuthService->createAccessToken($user, $userSecret);
        $refreshToken = $jwtAuthService->createRefreshToken($user, $userSecret);

        return compact('accessToken', 'refreshToken');
    }

    public function refreshSessions(\CurrentUser $currentUser, ORM $orm): true
    {
        $orm->updateBatch(
            User::class,
            [
                'sess_valid_from' => chronos()
            ],
            ['id' => $currentUser->getId()]
        );

        return true;
    }

    /**
     * @param  \CurrentUser  $currentUser
     *
     * @return  \CurrentUser
     *
     * @deprecated  Use user/me instead.
     */
    public function me(\CurrentUser $currentUser): \CurrentUser
    {
        return $currentUser;
    }

    /**
     * @param  AppContext  $app
     *
     * @return true
     *
     * @deprecated  Use user/deleteMe instead.
     */
    public function deleteMe(
        AppContext $app,
    ): true {
        return $app->dispatchController([UserController::class, 'deleteMe']);
    }

    /**
     * @param  ORM         $orm
     * @param  SRPService  $srpService
     * @param  User        $user
     * @param  string      $A
     * @param  string      $M1
     * @param  string      $sess
     *
     * @return  array{ 0: ProofResult, 1: \stdClass, 2: UserSecret }
     *
     * @throws NumberFormatException
     * @throws \Brick\Math\Exception\DivisionByZeroException
     * @throws \Brick\Math\Exception\MathException
     * @throws \Brick\Math\Exception\NegativeNumberException
     */
    protected function srpValidate(
        ORM $orm,
        SRPService $srpService,
        User $user,
        string $A,
        string $M1,
        string $sess
    ): array {
        $userSecret = $orm->mustFindOne(
            UserSecret::class,
            ['user_id' => uuid2bin($user->getId())]
        );

        if (!$loginToken = $user->getLoginToken()) {
            ErrorCode::INVALID_SESSION->throw();
        }

        $loginPayload = JWT::decode(
            $loginToken,
            new Key($userSecret->getDecodedServerSecret(), 'HS512')
        );

        $sessPayload = JWT::decode(
            $sess,
            new Key($userSecret->getDecodedServerSecret(), 'HS512')
        );

        if ($loginPayload->sess !== $sessPayload->sess) {
            ErrorCode::INVALID_SESSION->throw();
        }

        $password = $user->getPassword();

        $pf = SRPService::decodePasswordVerifier($password);

        $A = BigInteger::fromBase($A, 16);
        $M1 = BigInteger::fromBase($M1, 16);
        $b = BigInteger::fromBase($loginPayload->b, 16);
        $B = BigInteger::fromBase($loginPayload->B, 16);

        try {
            $server = $srpService->getSRPServer();
            $result = $server->step2(
                $user->getEmail(),
                $pf->salt,
                $pf->verifier,
                $A,
                $B,
                $b,
                $M1
            );

            return [$result, $loginPayload, $userSecret];
        } catch (InvalidSessionProofException) {
            ErrorCode::INVALID_CREDENTIALS->throw();
        }
    }
}
