// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"

#include "databasemanager.h"

#include "configmanager.h"
#include "luascript.h"
#include "scripting/LuaErrorWrap.h"
#include "utils/Logger.h"

#include <fmt/format.h>

bool DatabaseManager::optimizeTables() {
	Database& db = Database::getInstance();

	DBResult_ptr result = db.storeQuery(fmt::format("SELECT `TABLE_NAME` FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = {:s} AND `DATA_FREE` > 0", db.escapeString(getString(ConfigManager::MYSQL_DB))));
	if (!result) {
		return false;
	}

	do {
		const auto tableName = result->getString("TABLE_NAME");
                Logger::instance().info(fmt::format("Optimizing table {}...", tableName));

                if (db.executeQuery(fmt::format("OPTIMIZE TABLE `{:s}`", tableName))) {
                        Logger::instance().info(fmt::format("Optimization completed for {}", tableName));
                } else {
                        Logger::instance().error(fmt::format("Optimization failed for {}", tableName));
                }
	} while (result->next());
	return true;
}

bool DatabaseManager::tableExists(const std::string& tableName) {
	Database& db = Database::getInstance();
	return db.storeQuery(fmt::format("SELECT `TABLE_NAME` FROM `information_schema`.`tables` WHERE `TABLE_SCHEMA` = {:s} AND `TABLE_NAME` = {:s} LIMIT 1", db.escapeString(getString(ConfigManager::MYSQL_DB)), db.escapeString(tableName))).get();
}

bool DatabaseManager::isDatabaseSetup() {
	Database& db = Database::getInstance();
	return db.storeQuery(fmt::format("SELECT `TABLE_NAME` FROM `information_schema`.`tables` WHERE `TABLE_SCHEMA` = {:s}", db.escapeString(getString(ConfigManager::MYSQL_DB)))).get();
}

int32_t DatabaseManager::getDatabaseVersion() {
	if (!tableExists("server_config")) {
		Database& db = Database::getInstance();
		db.executeQuery("CREATE TABLE `server_config` (`config` VARCHAR(50) NOT NULL, `value` VARCHAR(256) NOT NULL DEFAULT '', UNIQUE(`config`)) ENGINE = InnoDB");
		db.executeQuery("INSERT INTO `server_config` VALUES ('db_version', 0)");
		return 0;
	}

	int32_t version = 0;
	if (getDatabaseConfig("db_version", version)) {
		return version;
	}
	return -1;
}

void DatabaseManager::updateDatabase() {
        lua_State* L = luaL_newstate();
        if (!L) {
                return;
        }

        lua_atpanic(L, luaPanic);
        luaL_openlibs(L);

#ifndef LUAJIT_VERSION
	//bit operations for Lua, based on bitlib project release 24
	//bit.bnot, bit.band, bit.bor, bit.bxor, bit.lshift, bit.rshift
	luaL_register(L, "bit", LuaScriptInterface::luaBitReg);
#endif

	//db table
	luaL_register(L, "db", LuaScriptInterface::luaDatabaseTable);

	//result table
	luaL_register(L, "result", LuaScriptInterface::luaResultTable);

	int32_t version = getDatabaseVersion();
	do {
                if (luaL_dofile(L, fmt::format("data/migrations/{:d}.lua", version).c_str()) != 0) {
                        Logger::instance().error(fmt::format("[Error - DatabaseManager::updateDatabase - Version: {}] {}", version, lua_tostring(L, -1)));
                        lua_pop(L, 1);
                        break;
                }

		if (!lua::reserveScriptEnv()) {
			break;
		}

		lua_getglobal(L, "onUpdateDatabase");
                if (!pcallWithTrace(L, 0, 1, fmt::format("data/migrations/{}.lua", version))) {
                        lua::resetScriptEnv();
                        Logger::instance().error(fmt::format("[Error - DatabaseManager::updateDatabase - Version: {}] {}", version, lua_tostring(L, -1)));
                        lua_pop(L, 1);
                        break;
                }

		if (!lua::getBoolean(L, -1, false)) {
			lua::resetScriptEnv();
			break;
		}

		version++;
                Logger::instance().info(fmt::format("Database has been updated to version {}.", version));
		registerDatabaseConfig("db_version", version);

		lua::resetScriptEnv();
	} while (true);
	lua_close(L);
}

bool DatabaseManager::getDatabaseConfig(const std::string& config, int32_t& value) {
	Database& db = Database::getInstance();

	DBResult_ptr result = db.storeQuery(fmt::format("SELECT `value` FROM `server_config` WHERE `config` = {:s}", db.escapeString(config)));
	if (!result) {
		return false;
	}

	value = result->getNumber<int32_t>("value");
	return true;
}

void DatabaseManager::registerDatabaseConfig(const std::string& config, int32_t value) {
	Database& db = Database::getInstance();

	int32_t tmp;

	if (!getDatabaseConfig(config, tmp)) {
		db.executeQuery(fmt::format("INSERT INTO `server_config` VALUES ({:s}, '{:d}')", db.escapeString(config), value));
	} else {
		db.executeQuery(fmt::format("UPDATE `server_config` SET `value` = '{:d}' WHERE `config` = {:s}", value, db.escapeString(config)));
	}
}