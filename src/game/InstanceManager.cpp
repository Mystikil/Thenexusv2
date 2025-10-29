// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"                // PCH must be first

#include "InstanceManager.h"         // same folder
#include "game.h"                    // same folder

#include "../creatures/player.h"     // up one, then into creatures/
#include "../creatures/monsters/monster.h"

#include "../scheduler.h"            // up one
// Use whichever your tree actually has:
#include "../logger.h"               // older TFS
// #include "../logging.h"           // some forks use logging.h instead

#if ENABLE_INSTANCING

InstanceManager& InstanceManager::get() {
        static InstanceManager instance;
        return instance;
}

uint32_t InstanceManager::create(const InstanceConfig& cfg) {
        const uint32_t uid = nextUid++;

        ActiveInstance active;
        active.uid = uid;
        active.name = cfg.name;
        active.start = time(nullptr);
        active.end = active.start + cfg.durationSeconds;
        active.warnAt = cfg.warnAt;
        active.expMult = cfg.expMult;
        active.lootMult = cfg.lootMult;
        active.hpMult = cfg.hpMult;
        active.dmgMult = cfg.dmgMult;
        active.armorMult = cfg.armorMult;
        active.entryPos = cfg.entryPos;
        active.exitPos = cfg.exitPos;
        active.bossNames = cfg.bossNames;
        active.partyOnly = cfg.partyOnly;
        active.minLevel = cfg.minLevel;
        active.cooldownSeconds = cfg.cooldownSeconds;
        active.seed = cfg.seed;

        instances.emplace(uid, active);
        fmt::print("[Instance] created uid={} name={}\n", uid, cfg.name);
        return uid;
}

bool InstanceManager::bindPlayer(Player* player, uint32_t uid, std::string* reason) {
        if (!player) {
                if (reason) {
                        *reason = "invalid player";
                }
                return false;
        }

        auto it = instances.find(uid);
        if (it == instances.end()) {
                if (reason) {
                        *reason = "instance not found";
                }
                return false;
        }

        it->second.players.insert(player->getGUID());
        player->setInstanceId(uid);
        return true;
}

bool InstanceManager::bindParty(Player* leader, uint32_t uid, std::string* reason) {
        // TODO: iterate over the leader party members and apply validation.
        return bindPlayer(leader, uid, reason);
}

bool InstanceManager::teleportInto(uint32_t uid, Player* playerOrLeader) {
        if (!playerOrLeader) {
                return false;
        }

        const auto it = instances.find(uid);
        if (it == instances.end()) {
                return false;
        }

        // TODO: perform teleportation and state preparation.
        return true;
}

void InstanceManager::onBossDeath(Monster* boss) {
        if (!boss) {
                return;
        }

        // TODO: identify owning instance and close it when the tracked boss dies.
}

void InstanceManager::close(uint32_t uid, const std::string& reason) {
        auto it = instances.find(uid);
        if (it == instances.end()) {
                return;
        }

        fmt::print("[Instance] closing uid={} reason={}\n", uid, reason);

        // TODO: teleport players out, clean up creatures and revoke bindings.
        instances.erase(it);
}

void InstanceManager::heartbeat() {
        const time_t now = time(nullptr);
        (void)now;
        for (auto it = instances.begin(); it != instances.end(); ++it) {
                ActiveInstance& instance = it->second;
                (void)instance;
                // TODO: emit warnings, close expired instances and handle scaling timers.
        }

        g_scheduler.addEvent(createSchedulerTask(5000, []() {
                InstanceManager::get().heartbeat();
        }));
}

bool InstanceManager::isPlayerBound(uint32_t guid, uint32_t uid) const {
        const auto it = instances.find(uid);
        if (it == instances.end()) {
                return false;
        }
        return it->second.players.contains(guid);
}

bool InstanceManager::playerLeave(Player* player) {
        if (!player) {
                return false;
        }

        for (auto& entry : instances) {
                if (entry.second.players.erase(player->getGUID()) != 0) {
                        player->resetToWorldInstance();
                        return true;
                }
        }
        return false;
}

#endif // ENABLE_INSTANCING
