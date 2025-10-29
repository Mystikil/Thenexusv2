# Experimental Instance Support

This branch adds the scaffolding required to experiment with engine-level instancing for The Forgotten Server 10.98. The feature is behind the `ENABLE_INSTANCING` compile-time flag (enabled by default in `src/definitions.h`).

## Building

1. Configure and build the server as usual (e.g. `cmake --build build`).
2. To disable the experimental code paths, add `-DENABLE_INSTANCING=0` to your compiler definitions.

## Runtime Hooks

* `src/game/InstanceManager.{h,cpp}` holds the singleton responsible for coordinating active instances. The current implementation focuses on structure and logging; TODO comments highlight the pending orchestration logic (spawn tracking, party validation, scaling, etc.).
* Creatures track a `uint32_t instanceId`. Use `Creature::setInstanceId` to move entities between instances. Players expose `Player::resetToWorldInstance()` as a convenience helper for the world layer.
* `Map::getSpectatorsByInstance` mirrors the existing spectator gathering routine while filtering by instance id. Calls are expected to gradually migrate to the new helper.
* `ProtocolGame::sendAddCreature` now ignores cross-instance describes when instancing is enabled.

## Lua Bridge Samples

The Lua snippets under `data/actions/scripts/instance_portal.lua` and `data/talkactions/scripts/instance_admin.lua` provide a minimal flow for spinning up an instance, binding a party, and inspecting/closing active runs. Register the scripts in the corresponding XML files to expose the behaviour in-game.

These examples depend on forthcoming C++ bindings:

```lua
createInstance(cfg)
bindPlayer(uid, player)
bindParty(uid, leader)
teleportInto(uid, player)
closeInstance(uid)
getActiveInstances()
```

Once the remaining TODO blocks in the C++ layer are completed the Lua helpers can be extended to cover more elaborate dungeon workflows (cooldowns, matchmaking, matchmaking UI, etc.).
