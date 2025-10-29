// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"                // PCH must be first

#include "InstanceManager.h"         // same folder
#include "game.h"                    // same folder

#include "../creatures/player.h"     // up one, then into creatures/
#include "../creatures/monsters/monster.h"
#include "../party.h"

#include "../scheduler.h"            // up one
// Use whichever your tree actually has:
#include "../logger.h"               // older TFS
// #include "../logging.h"           // some forks use logging.h instead
#include "../tools.h"

#include <algorithm>
#include <cctype>
#include <fstream>
#include <string_view>

#include <fmt/format.h>

#if ENABLE_INSTANCING

extern Game g_game;

namespace {
        bool hasOtbmExtension(const std::string& name) {
                const std::string_view suffix = ".otbm";
                if (name.size() < suffix.size()) {
                        return false;
                }
                return std::equal(suffix.rbegin(), suffix.rend(), name.rbegin(), name.rend(),
                                  [](char a, char b) {
                                          return std::tolower(static_cast<unsigned char>(a)) ==
                                                 std::tolower(static_cast<unsigned char>(b));
                                  });
        }
}

InstanceManager& InstanceManager::get() {
        static InstanceManager instance;
        return instance;
}

uint32_t InstanceManager::create(const InstanceConfig& cfg) {
        if (!ensureMapLoaded(cfg.mapName)) {
                fmt::print("[Instance] failed to prepare map '{}' for instance '{}', creation aborted.\n", cfg.mapName, cfg.name);
                return 0;
        }

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
        active.mapName = cfg.mapName;
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

        ActiveInstance& instance = it->second;
        if (instance.minLevel > player->getLevel()) {
                if (reason) {
                        *reason = fmt::format("requires level {}", instance.minLevel);
                }
                return false;
        }

        if (player->getInstanceId() != 0 && player->getInstanceId() != uid) {
                if (reason) {
                        *reason = "already bound to a different instance";
                }
                return false;
        }

        instance.players.insert(player->getGUID());
        player->setInstanceId(uid);
        return true;
}

bool InstanceManager::bindParty(Player* leader, uint32_t uid, std::string* reason) {
        if (!leader) {
                if (reason) {
                        *reason = "invalid player";
                }
                return false;
        }

        Party* party = leader->getParty();
        if (!party) {
                return bindPlayer(leader, uid, reason);
        }

        std::vector<Player*> bound;
        bound.reserve(party->getMembers().size() + 1);

        std::string localReason;
        if (!bindPlayer(leader, uid, &localReason)) {
                if (reason) {
                        *reason = localReason;
                }
                return false;
        }
        bound.push_back(leader);

        for (Player* member : party->getMembers()) {
                if (!member) {
                        continue;
                }
                if (!bindPlayer(member, uid, &localReason)) {
                        if (reason) {
                                *reason = localReason;
                        }
                        for (Player* unbind : bound) {
                                playerLeave(unbind);
                        }
                        return false;
                }
                bound.push_back(member);
        }

        return true;
}

bool InstanceManager::teleportInto(uint32_t uid, Player* playerOrLeader, std::string* reason) {
        if (!playerOrLeader) {
                if (reason) {
                        *reason = "invalid player";
                }
                return false;
        }

        const auto it = instances.find(uid);
        if (it == instances.end()) {
                if (reason) {
                        *reason = "instance not found";
                }
                return false;
        }

        ActiveInstance& instance = it->second;

        if (!bindPlayer(playerOrLeader, uid, reason)) {
                return false;
        }

        if (!ensureMapLoaded(instance.mapName)) {
                if (reason) {
                        *reason = fmt::format("failed to load map '{}'", instance.mapName);
                }
                playerLeave(playerOrLeader);
                return false;
        }

        ReturnValue result = g_game.internalTeleport(playerOrLeader, instance.entryPos, true);
        if (result != RETURNVALUE_NOERROR) {
                if (reason) {
                        *reason = getReturnMessage(result);
                }
                playerLeave(playerOrLeader);
                return false;
        }

        fmt::print("[Instance] teleport uid={} player={} map={} position=({},{},{})\n", uid, playerOrLeader->getName(),
                   instance.mapName, instance.entryPos.x, instance.entryPos.y, static_cast<int>(instance.entryPos.z));
        return true;
}

void InstanceManager::onBossDeath(Monster* boss) {
        if (!boss) {
                return;
        }

        // TODO: identify owning instance and close it when the tracked boss dies.
}

bool InstanceManager::close(uint32_t uid, const std::string& reason) {
        auto it = instances.find(uid);
        if (it == instances.end()) {
                return false;
        }

        ActiveInstance instance = it->second;
        fmt::print("[Instance] closing uid={} reason={}\n", uid, reason);

        std::vector<Player*> playersToExit;
        playersToExit.reserve(instance.players.size());
        for (uint32_t guid : instance.players) {
                if (Player* player = g_game.getPlayerByGUID(guid)) {
                        playersToExit.push_back(player);
                }
        }

        for (Player* player : playersToExit) {
                if (!player) {
                        continue;
                }

                if (!isDefaultPosition(instance.exitPos)) {
                        if (g_game.internalTeleport(player, instance.exitPos, true) != RETURNVALUE_NOERROR) {
                                g_game.internalTeleport(player, player->getTemplePosition(), true);
                        }
                } else {
                        g_game.internalTeleport(player, player->getTemplePosition(), true);
                }

                player->sendTextMessage(MESSAGE_EVENT_ADVANCE,
                                        fmt::format("Instance '{}' has ended: {}", instance.name, reason));
                player->resetToWorldInstance();
        }

        instances.erase(it);
        return true;
}

void InstanceManager::heartbeat() {
        const time_t now = time(nullptr);
        std::vector<uint32_t> expired;
        expired.reserve(instances.size());

        for (const auto& entry : instances) {
                const ActiveInstance& instance = entry.second;
                if (instance.end != 0 && now >= instance.end) {
                        expired.push_back(entry.first);
                }
        }

        for (uint32_t uid : expired) {
                close(uid, "expired");
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

bool InstanceManager::ensureMapLoaded(const std::string& mapName) {
        if (mapName.empty()) {
                return true;
        }

        if (loadedMaps.contains(mapName)) {
                return true;
        }

        std::string path = "data/world/" + mapName;
        if (!hasOtbmExtension(mapName)) {
                path += ".otbm";
        }

        std::ifstream handle(path);
        if (!handle.good()) {
                fmt::print("[Instance] map template '{}' not found, assuming it is already part of the main map.\n", path);
                loadedMaps.insert(mapName);
                return true;
        }

        handle.close();
        fmt::print("[Instance] loading map template '{}'\n", path);
        g_game.loadMap(path, true);
        loadedMaps.insert(mapName);
        return true;
}

bool InstanceManager::isDefaultPosition(const Position& pos) {
        return pos.x == 0 && pos.y == 0 && pos.z == 0;
}

#endif // ENABLE_INSTANCING
