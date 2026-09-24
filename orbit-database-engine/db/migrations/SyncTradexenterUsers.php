<?php
use Shared\Schema\HVSchema;
use Tradexenter\Schema\TradexenterSchema;

class SyncTradexenterUsers {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function up(): void {
        // 1. Get users from Tradexenter
        // We use the query() helper you have globally
        $oldUsers = query('tradexenter')
            ->table(TradexenterSchema::table('USER_AUTH'))
            ->get();

        foreach ($oldUsers as $user) {
            // 2. Check if already in HV_USERS
            $exists = query('hypervirtue')
                ->table(HVSchema::TABLES['HV_USERS'])
                ->where('email', '=', $user['email'])
                ->first();

            if (!$exists) {
                // 3. Create the Identity
                $hvUserId = query('hypervirtue')
                    ->table(HVSchema::TABLES['HV_USERS'])
                    ->insert([
                        'email'         => $user['email'],
                        'password_hash' => $user['password'],
                        'role'          => strtolower($user['role']),
                        'is_active'     => ($user['status'] === 'active') ? 1 : 0,
                        'created'       => $user['created']
                    ]);

                // 4. Create the App Permission Link
                query('hypervirtue')
                    ->table(HVSchema::TABLES['HV_USER_APPS'])
                    ->insert([
                        'user_id'     => $hvUserId,
                        'app_name'    => 'tradexenter',
                        'provider_id' => $user['provider_id'] ?? null,
                        'is_active'   => 1
                    ]);
            }
        }
    }
}