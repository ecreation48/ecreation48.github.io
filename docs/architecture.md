# Architecture — phase 1

`discord-manager` peut être répliqué. Chaque instance découvre les bots actifs via Laravel, mais ne démarre un client qu'après acquisition atomique du verrou Redis du bot. Un heartbeat associe ensuite le bot à une `worker_instance`. La perte du verrou provoque l'arrêt du client.

Les tokens Discord sont chiffrés par le cast `encrypted` de Laravel. L'endpoint de credentials exige un bearer token de service et n'accepte que les bots actifs. En production, le token de service doit être fourni par le gestionnaire de secrets et renouvelé lors d'un redéploiement coordonné.
