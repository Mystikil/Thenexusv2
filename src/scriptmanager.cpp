// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"

#include "scriptmanager.h"

#include "actions.h"
#include "chat.h"
#include "events.h"
#include "globalevent.h"
#include "movement.h"
#include "script.h"
#include "spells.h"
#include "talkaction.h"
#include "utils/Logger.h"
#include "weapons.h"

Actions* g_actions = nullptr;
CreatureEvents* g_creatureEvents = nullptr;
Chat* g_chat = nullptr;
GlobalEvents* g_globalEvents = nullptr;
Spells* g_spells = nullptr;
TalkActions* g_talkActions = nullptr;
MoveEvents* g_moveEvents = nullptr;
Weapons* g_weapons = nullptr;
Scripts* g_scripts = nullptr;

extern LuaEnvironment g_luaEnvironment;

ScriptingManager::~ScriptingManager() {
	delete g_weapons;
	delete g_spells;
	delete g_actions;
	delete g_talkActions;
	delete g_moveEvents;
	delete g_chat;
	delete g_creatureEvents;
	delete g_globalEvents;
	delete g_scripts;
}

bool ScriptingManager::loadScriptSystems() {
        if (g_luaEnvironment.loadFile("data/global.lua") == -1) {
                Logger::instance().warn("[Warning - ScriptingManager::loadScriptSystems] Cannot load data/global.lua");
        }

        g_scripts = new Scripts();
        Logger::instance().info("Loading lua libs");
        if (!g_scripts->loadScripts("scripts/lib", true, false)) {
                Logger::instance().fatal("Unable to load lua libs!");
                return false;
        }

	g_chat = new Chat();

	g_weapons = new Weapons();
        if (!g_weapons->loadFromXml()) {
                Logger::instance().fatal("Unable to load weapons!");
                return false;
        }

	g_weapons->loadDefaults();

	g_spells = new Spells();
        if (!g_spells->loadFromXml()) {
                Logger::instance().fatal("Unable to load spells!");
                return false;
        }

	g_actions = new Actions();
        if (!g_actions->loadFromXml()) {
                Logger::instance().fatal("Unable to load actions!");
                return false;
        }

	g_talkActions = new TalkActions();
        if (!g_talkActions->loadFromXml()) {
                Logger::instance().fatal("Unable to load talk actions!");
                return false;
        }

	g_moveEvents = new MoveEvents();
        if (!g_moveEvents->loadFromXml()) {
                Logger::instance().fatal("Unable to load move events!");
                return false;
        }

	g_creatureEvents = new CreatureEvents();
        if (!g_creatureEvents->loadFromXml()) {
                Logger::instance().fatal("Unable to load creature events!");
                return false;
        }

	g_globalEvents = new GlobalEvents();
        if (!g_globalEvents->loadFromXml()) {
                Logger::instance().fatal("Unable to load global events!");
                return false;
        }

        if (!events::load()) {
                Logger::instance().fatal("Unable to load events!");
                return false;
        }

	return true;
}