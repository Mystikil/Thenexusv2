// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#pragma once

#if ENABLE_INSTANCING

#include <ctime>
#include <map>
#include <string>
#include <unordered_set>
#include <vector>
#include "../position.h"


class Player;
class Monster;

struct InstanceConfig {
        std::string name;
        uint32_t durationSeconds = 1800;
        std::vector<uint32_t> warnAt;
        float expMult = 1.0f;
        float lootMult = 1.0f;
        float hpMult = 1.0f;
        float dmgMult = 1.0f;
        float armorMult = 1.0f;
        std::vector<std::string> bossNames;
        Position entryPos;
        Position exitPos;
        bool partyOnly = false;
        uint16_t minLevel = 1;
        uint32_t cooldownSeconds = 0;
        uint32_t seed = 0;
};

struct ActiveInstance {
        uint32_t uid = 0;
        std::string name;
        time_t start = 0;
        time_t end = 0;
        std::vector<uint32_t> warnAt;
        float expMult = 1.0f;
        float lootMult = 1.0f;
        float hpMult = 1.0f;
        float dmgMult = 1.0f;
        float armorMult = 1.0f;
        Position entryPos;
        Position exitPos;
        std::unordered_set<uint32_t> players;
        std::unordered_set<uint32_t> creatures;
        std::vector<std::string> bossNames;
        bool partyOnly = false;
        uint16_t minLevel = 1;
        uint32_t cooldownSeconds = 0;
        uint32_t seed = 0;
};

class InstanceManager {
        public:
                static InstanceManager& get();

                uint32_t create(const InstanceConfig& cfg);
                bool bindPlayer(Player* player, uint32_t uid, std::string* reason = nullptr);
                bool bindParty(Player* leader, uint32_t uid, std::string* reason = nullptr);
                bool teleportInto(uint32_t uid, Player* playerOrLeader);
                void onBossDeath(Monster* boss);
                void close(uint32_t uid, const std::string& reason);
                void heartbeat();
                const std::map<uint32_t, ActiveInstance>& list() const {
                        return instances;
                }
                bool isPlayerBound(uint32_t guid, uint32_t uid) const;
                bool playerLeave(Player* player);

        private:
                InstanceManager() = default;

                uint32_t nextUid = 1;
                std::map<uint32_t, ActiveInstance> instances;
};

#endif // ENABLE_INSTANCING
