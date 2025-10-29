// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"

#include "otserv.h"

#include "configmanager.h"
#include "databasemanager.h"
#include "databasetasks.h"
#include "game/game.h"
#include "iomarket.h"
#include "monsters.h"
#include "monster/Rank.hpp"
#include "outfit.h"
#include "protocollogin.h"
#include "protocolold.h"
#include "protocolstatus.h"
#include "rsa.h"
#include "scheduler.h"
#include "script.h"
#include "scriptmanager.h"
#include "server.h"
#include "utils/Logger.h"
#include "utils/StartupProbe.h"
#include "world/WorldPressureManager.hpp"

#ifdef WITH_PYTHON
#include "python/PythonEngine.h"
#endif

#include <algorithm>
#include <array>
#include <cctype>
#include <fstream>
#include <fmt/format.h>
#include <unordered_map>
#include <unordered_set>
#include <utility>
#include <vector>

#include <boost/algorithm/string.hpp>

#if __has_include("gitmetadata.h")
#include "gitmetadata.h"
#endif

DatabaseTasks g_databaseTasks;
Dispatcher g_dispatcher;
Scheduler g_scheduler;

Game g_game;
Monsters g_monsters;
Vocations g_vocations;
extern Scripts* g_scripts;

std::mutex g_loaderLock;
std::condition_variable g_loaderSignal;
std::unique_lock<std::mutex> g_loaderUniqueLock(g_loaderLock);
std::atomic_bool g_startupFailed{ false };

namespace {

    struct ColumnSpec {
        std::string name;
        std::string type;
        bool nullable;
    };

    struct IndexSpec {
        std::string name;
        std::vector<std::string> columns;
        bool unique;
        bool primary;
    };

    struct ForeignKeySpec {
        std::string name;
        std::string column;
        std::string referencedTable;
        std::string referencedColumn;
    };

    struct TableSchemaSpec {
        std::string name;
        std::vector<ColumnSpec> columns;
        std::vector<std::string> primaryKey;
        std::vector<IndexSpec> indexes;
        std::vector<ForeignKeySpec> foreignKeys;
    };

    std::string normalizeIdentifier(std::string value) {
        boost::algorithm::to_lower(value);
        return value;
    }

    std::string normalizeColumnType(std::string value) {
        boost::algorithm::to_lower(value);
        value.erase(std::remove_if(value.begin(), value.end(), [](unsigned char ch) { return std::isspace(ch); }), value.end());

        bool isUnsigned = false;
        if (const auto pos = value.find("unsigned"); pos != std::string::npos) {
            isUnsigned = true;
            value.erase(pos, std::string("unsigned").size());
        }

        std::string baseType = value;
        std::string modifiers;
        if (const auto parenPos = value.find('('); parenPos != std::string::npos) {
            baseType = value.substr(0, parenPos);
            modifiers = value.substr(parenPos);
        }

        static const std::unordered_set<std::string> integerTypes = {
                "tinyint", "smallint", "mediumint", "int", "integer", "bigint",
        };

        if (integerTypes.find(baseType) != integerTypes.end()) {
            modifiers.clear();
        }

        std::string canonical = baseType + modifiers;
        if (isUnsigned) {
            canonical.append(" unsigned");
        }

        return canonical;
    }

    void logSchemaNormalizationExamples() {
        static bool logged = false;
        if (logged) {
            return;
        }
        logged = true;

        constexpr std::array<std::pair<const char*, const char*>, 3> examples = { {
                {"int(11)", "int"},
                {"smallint unsigned", "smallint(5) unsigned"},
                {"bigint(20)", "bigint"},
        } };

        for (const auto& [lhs, rhs] : examples) {
            const auto lhsNormalized = normalizeColumnType(lhs);
            const auto rhsNormalized = normalizeColumnType(rhs);
            const bool equal = lhsNormalized == rhsNormalized;
            Logger::instance().debug(fmt::format(
                "Schema normalization example: \"{}\" == \"{}\" -> {} ({} vs {})",
                lhs,
                rhs,
                equal ? "true" : "false",
                lhsNormalized,
                rhsNormalized));
        }
    }

    bool verifyTableSchema(const TableSchemaSpec& spec, std::vector<std::string>& messages) {
        logSchemaNormalizationExamples();

        Database& db = Database::getInstance();
        const auto schemaName = getString(ConfigManager::MYSQL_DB);

        auto existsQuery = fmt::format(
            "SELECT COUNT(*) AS `count` FROM information_schema.tables WHERE table_schema = {:s} AND table_name = {:s}",
            db.escapeString(schemaName), db.escapeString(spec.name));
        DBResult_ptr exists = db.storeQuery(existsQuery);
        if (!exists || exists->getNumber<uint32_t>("count") == 0) {
            messages.emplace_back("table missing");
            return false;
        }

        std::unordered_map<std::string, std::pair<std::string, bool>> columns;
        auto columnQuery = fmt::format(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns WHERE table_schema = {:s} AND table_name = {:s}",
            db.escapeString(schemaName), db.escapeString(spec.name));
        if (DBResult_ptr columnResult = db.storeQuery(columnQuery)) {
            do {
                const auto columnName = normalizeIdentifier(std::string(columnResult->getString("COLUMN_NAME")));
                const auto columnType = normalizeColumnType(std::string(columnResult->getString("COLUMN_TYPE")));
                const bool nullable = boost::iequals(columnResult->getString("IS_NULLABLE"), "YES");
                columns.emplace(columnName, std::make_pair(columnType, nullable));
            } while (columnResult->next());
        }

        bool ok = true;
        for (const auto& column : spec.columns) {
            const auto key = normalizeIdentifier(column.name);
            auto it = columns.find(key);
            if (it == columns.end()) {
                messages.emplace_back(fmt::format("missing column '{}'", column.name));
                ok = false;
                continue;
            }

            const auto expectedType = normalizeColumnType(column.type);
            if (it->second.first != expectedType) {
                messages.emplace_back(fmt::format("column '{}' type mismatch (expected {}, got {})", column.name, expectedType, it->second.first));
                ok = false;
            }

            if (column.nullable != it->second.second) {
                messages.emplace_back(fmt::format("column '{}' nullability mismatch", column.name));
                ok = false;
            }
        }

        // Verify primary key order
        if (!spec.primaryKey.empty()) {
            std::vector<std::string> actualPk;
            auto pkQuery = fmt::format(
                "SELECT COLUMN_NAME FROM information_schema.key_column_usage WHERE table_schema = {:s} AND table_name = {:s} AND constraint_name = 'PRIMARY' ORDER BY ORDINAL_POSITION",
                db.escapeString(schemaName), db.escapeString(spec.name));
            if (DBResult_ptr pkResult = db.storeQuery(pkQuery)) {
                do {
                    actualPk.emplace_back(normalizeIdentifier(std::string(pkResult->getString("COLUMN_NAME"))));
                } while (pkResult->next());
            }

            std::vector<std::string> expectedPk;
            for (const auto& column : spec.primaryKey) {
                expectedPk.emplace_back(normalizeIdentifier(column));
            }

            if (actualPk != expectedPk) {
                messages.emplace_back("primary key mismatch");
                ok = false;
            }
        }

        std::unordered_map<std::string, IndexSpec> actualIndexes;
        auto indexQuery = fmt::format(
            "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.statistics WHERE table_schema = {:s} AND table_name = {:s} ORDER BY INDEX_NAME, SEQ_IN_INDEX",
            db.escapeString(schemaName), db.escapeString(spec.name));
        if (DBResult_ptr indexResult = db.storeQuery(indexQuery)) {
            do {
                const auto indexName = std::string(indexResult->getString("INDEX_NAME"));
                auto& entry = actualIndexes[indexName];
                if (entry.name.empty()) {
                    entry.name = indexName;
                    entry.unique = indexResult->getNumber<uint32_t>("NON_UNIQUE") == 0;
                    entry.primary = boost::iequals(indexName, "PRIMARY");
                }
                const auto seq = static_cast<size_t>(indexResult->getNumber<uint32_t>("SEQ_IN_INDEX"));
                if (seq > entry.columns.size()) {
                    entry.columns.resize(seq);
                }
                entry.columns[seq - 1] = normalizeIdentifier(std::string(indexResult->getString("COLUMN_NAME")));
            } while (indexResult->next());
        }

        for (const auto& expectedIndex : spec.indexes) {
            auto it = actualIndexes.find(expectedIndex.name);
            if (it == actualIndexes.end()) {
                messages.emplace_back(fmt::format("missing index '{}'", expectedIndex.name));
                ok = false;
                continue;
            }

            if (it->second.primary != expectedIndex.primary) {
                messages.emplace_back(fmt::format("index '{}' primary flag mismatch", expectedIndex.name));
                ok = false;
            }

            if (expectedIndex.unique && !it->second.unique) {
                messages.emplace_back(fmt::format("index '{}' is not unique", expectedIndex.name));
                ok = false;
            }

            std::vector<std::string> expectedColumns;
            expectedColumns.reserve(expectedIndex.columns.size());
            for (const auto& column : expectedIndex.columns) {
                expectedColumns.emplace_back(normalizeIdentifier(column));
            }
            if (expectedColumns != it->second.columns) {
                messages.emplace_back(fmt::format("index '{}' columns mismatch", expectedIndex.name));
                ok = false;
            }
        }

        std::unordered_map<std::string, ForeignKeySpec> actualFks;
        auto fkQuery = fmt::format(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.key_column_usage WHERE table_schema = {:s} AND table_name = {:s} AND REFERENCED_TABLE_NAME IS NOT NULL",
            db.escapeString(schemaName), db.escapeString(spec.name));
        if (DBResult_ptr fkResult = db.storeQuery(fkQuery)) {
            do {
                ForeignKeySpec specEntry;
                specEntry.name = std::string(fkResult->getString("CONSTRAINT_NAME"));
                specEntry.column = normalizeIdentifier(std::string(fkResult->getString("COLUMN_NAME")));
                specEntry.referencedTable = normalizeIdentifier(std::string(fkResult->getString("REFERENCED_TABLE_NAME")));
                specEntry.referencedColumn = normalizeIdentifier(std::string(fkResult->getString("REFERENCED_COLUMN_NAME")));
                actualFks.emplace(specEntry.name, specEntry);
            } while (fkResult->next());
        }

        for (const auto& fk : spec.foreignKeys) {
            auto it = actualFks.find(fk.name);
            if (it == actualFks.end()) {
                messages.emplace_back(fmt::format("missing foreign key '{}'", fk.name));
                ok = false;
                continue;
            }

            if (it->second.column != normalizeIdentifier(fk.column) ||
                it->second.referencedTable != normalizeIdentifier(fk.referencedTable) ||
                it->second.referencedColumn != normalizeIdentifier(fk.referencedColumn)) {
                messages.emplace_back(fmt::format("foreign key '{}' definition mismatch", fk.name));
                ok = false;
            }
        }

        return ok;
    }

    bool verifyReputationEconomySchema() {
        constexpr int32_t requiredVersion = 33;
        std::vector<TableSchemaSpec> specs = {
                {
                        "factions",
                        {
                                {"id", "smallint unsigned", false},
                                {"name", "varchar(64)", false},
                                {"description", "varchar(255)", false},
                                {"npc_buy_fee", "decimal(6,4)", false},
                                {"npc_sell_fee", "decimal(6,4)", false},
                                {"market_fee", "decimal(6,4)", false},
                                {"trade_buy_factor", "decimal(10,6)", false},
                                {"trade_sell_factor", "decimal(10,6)", false},
                                {"donation_multiplier", "decimal(10,6)", false},
                                {"kill_penalty", "int", false},
                                {"decay_per_week", "int", false},
                                {"soft_cap", "int", false},
                                {"hard_cap", "int", false},
                                {"soft_diminish", "decimal(6,4)", false},
                                {"created_at", "int", false},
                                {"updated_at", "int", false},
                        },
                        {"id"},
                        {
                                {"PRIMARY", {"id"}, true, true},
                                {"idx_factions_name", {"name"}, true, false},
                        },
                        {}
                },
                {
                        "npc_factions",
                        {
                                {"npc_name", "varchar(64)", false},
                                {"faction_id", "smallint unsigned", false},
                        },
                        {"npc_name"},
                        {
                                {"PRIMARY", {"npc_name"}, true, true},
                                {"idx_npc_faction", {"faction_id"}, false, false},
                        },
                        {
                                {"fk_npc_faction", "faction_id", "factions", "id"},
                        }
                },
                {
                        "player_faction_reputation",
                        {
                                {"player_id", "int", false},
                                {"faction_id", "smallint unsigned", false},
                                {"reputation", "int", false},
                                {"last_activity", "int", false},
                                {"last_decay", "int", false},
                        },
                        {"player_id", "faction_id"},
                        {
                                {"PRIMARY", {"player_id", "faction_id"}, true, true},
                                {"idx_faction_player", {"faction_id", "player_id"}, false, false},
                        },
                        {
                                {"fk_rep_player", "player_id", "players", "id"},
                                {"fk_rep_faction", "faction_id", "factions", "id"},
                        }
                },
                {
                        "player_faction_reputation_log",
                        {
                                {"id", "bigint unsigned", false},
                                {"player_id", "int", false},
                                {"faction_id", "smallint unsigned", false},
                                {"delta", "int", false},
                                {"source", "varchar(64)", false},
                                {"context", "text", false},
                                {"created_at", "int", false},
                        },
                        {"id"},
                        {
                                {"PRIMARY", {"id"}, true, true},
                                {"idx_rep_log_player", {"player_id"}, false, false},
                                {"idx_rep_log_faction", {"faction_id"}, false, false},
                        },
                        {
                                {"fk_rep_log_player", "player_id", "players", "id"},
                                {"fk_rep_log_faction", "faction_id", "factions", "id"},
                        }
                },
                {
                        "faction_economy",
                        {
                                {"faction_id", "smallint unsigned", false},
                                {"pool", "bigint", false},
                                {"updated_at", "int", false},
                        },
                        {"faction_id"},
                        {
                                {"PRIMARY", {"faction_id"}, true, true},
                        },
                        {
                                {"fk_economy_faction", "faction_id", "factions", "id"},
                        }
                },
                {
                        "faction_economy_history",
                        {
                                {"id", "bigint unsigned", false},
                                {"faction_id", "smallint unsigned", false},
                                {"delta", "bigint", false},
                                {"reason", "varchar(128)", false},
                                {"reference_id", "int", false},
                                {"created_at", "int", false},
                        },
                        {"id"},
                        {
                                {"PRIMARY", {"id"}, true, true},
                                {"idx_economy_history_faction", {"faction_id"}, false, false},
                        },
                        {
                                {"fk_economy_history_faction", "faction_id", "factions", "id"},
                        }
                },
                {
                        "faction_economy_ledger",
                        {
                                {"id", "bigint unsigned", false},
                                {"faction_id", "smallint unsigned", false},
                                {"delta", "bigint", false},
                                {"reason", "varchar(128)", false},
                                {"reference_id", "int", false},
                                {"created_at", "int", false},
                                {"processed", "tinyint", false},
                                {"processed_at", "int", false},
                        },
                        {"id"},
                        {
                                {"PRIMARY", {"id"}, true, true},
                                {"idx_economy_ledger_processed", {"processed", "id"}, false, false},
                        },
                        {
                                {"fk_economy_ledger_faction", "faction_id", "factions", "id"},
                        }
                },
                {
                        "faction_market_cursor",
                        {
                                {"id", "tinyint unsigned", false},
                                {"last_history_id", "int unsigned", false},
                                {"updated_at", "int", false},
                        },
                        {"id"},
                        {
                                {"PRIMARY", {"id"}, true, true},
                        },
                        {}
                },
        };

        bool allOk = true;
        for (const auto& spec : specs) {
            std::vector<std::string> errors;
            if (verifyTableSchema(spec, errors)) {
                Logger::instance().info(fmt::format("SCHEMA OK: table {}", spec.name));
            }
            else {
                allOk = false;
                Logger::instance().error(fmt::format("SCHEMA MISSING: {} ({})", spec.name, boost::algorithm::join(errors, "; ")));
            }
        }

        if (!allOk) {
            return false;
        }

        int32_t version = DatabaseManager::getDatabaseVersion();
        if (version < requiredVersion) {
            Logger::instance().error(fmt::format("Database version {} is older than required {}", version, requiredVersion));
            return false;
        }

        return true;
    }

    void startupErrorMessage(const std::string& errorStr) {
        Logger::instance().fatal(errorStr);
        g_startupFailed.store(true, std::memory_order_relaxed);
        g_loaderSignal.notify_all();
    }

    void mainLoader(ServiceManager* services) {
        //dispatcher thread
        g_game.setGameState(GAME_STATE_STARTUP);
        StartupProbe::mark("begin");

        g_startupFailed.store(false, std::memory_order_relaxed);
        auto& logger = Logger::instance();

        bool reputationEnabled = true;
        bool economyEnabled = true;

        srand(static_cast<unsigned int>(OTSYS_TIME()));
#ifdef _WIN32
        SetConsoleTitle(STATUS_SERVER_NAME);

        // fixes a problem with escape characters not being processed in Windows consoles
        HANDLE hOut = GetStdHandle(STD_OUTPUT_HANDLE);
        DWORD dwMode = 0;
        GetConsoleMode(hOut, &dwMode);
        dwMode |= ENABLE_VIRTUAL_TERMINAL_PROCESSING;
        SetConsoleMode(hOut, dwMode);
#endif

        printServerVersion();

        // check if config.lua or config.lua.dist exist
        const std::string& configFile = getString(ConfigManager::CONFIG_FILE);
        std::ifstream c_test("./" + configFile);
        if (!c_test.is_open()) {
            std::ifstream config_lua_dist("./config.lua.dist");
            if (config_lua_dist.is_open()) {
                logger.info(fmt::format("Copying config.lua.dist to {}", configFile));
                std::ofstream config_lua(configFile);
                config_lua << config_lua_dist.rdbuf();
                config_lua.close();
                config_lua_dist.close();
            }
        }
        else {
            c_test.close();
        }

        // read global config
        logger.info("Loading config");
        if (!ConfigManager::load()) {
            startupErrorMessage("Unable to load " + configFile + "!");
            return;
        }

        reputationEnabled = getBoolean(ConfigManager::ENABLE_REPUTATION_SYSTEM);
        economyEnabled = getBoolean(ConfigManager::ENABLE_ECONOMY_SYSTEM);
        StartupProbe::mark("config");

#ifdef WITH_PYTHON
        if (getBoolean(ConfigManager::PYTHON_ENABLED)) {
            const std::string& pythonHome = getString(ConfigManager::PYTHON_HOME);
            const std::string& pythonModulePath = getString(ConfigManager::PYTHON_MODULE_PATH);
            const std::string& pythonEntry = getString(ConfigManager::PYTHON_ENTRY);
            if (!PythonEngine::instance().init(pythonHome, pythonModulePath, pythonEntry)) {
                Logger::instance().warn("[Python] Failed to initialize embedded runtime; continuing without Python.");
            }
        }
#endif

        std::string rerr;
        RankSystem::get().loadFromJson("data/monster_ranks.json", rerr);
        if (!rerr.empty()) {
            logger.warn(fmt::format("[Ranks] {}", rerr));
        }

        std::string perr;
        WorldPressureManager::get().loadJson("data/rank_pressure.json", perr);
        if (!perr.empty()) {
            logger.warn(fmt::format("[Pressure] {}", perr));
        }

#ifdef _WIN32
        const std::string& defaultPriority = getString(ConfigManager::DEFAULT_PRIORITY);
        if (caseInsensitiveEqual(defaultPriority, "high")) {
            SetPriorityClass(GetCurrentProcess(), HIGH_PRIORITY_CLASS);
        }
        else if (caseInsensitiveEqual(defaultPriority, "above-normal")) {
            SetPriorityClass(GetCurrentProcess(), ABOVE_NORMAL_PRIORITY_CLASS);
        }
#endif

        //set RSA key
        logger.info("Loading RSA key");
        try {
            std::ifstream key{ "key.pem" };
            std::string pem{ std::istreambuf_iterator<char>{key}, std::istreambuf_iterator<char>{} };
            rsa::loadPEM(pem);
        }
        catch (const std::exception& e) {
            startupErrorMessage(e.what());
            return;
        }

        logger.info("Establishing database connection...");

        if (!Database::getInstance().connect()) {
            startupErrorMessage("Failed to connect to database.");
            return;
        }

        logger.info(fmt::format("Connected to MySQL {}", Database::getClientVersion()));
        StartupProbe::mark("database");

        // run database manager
        logger.info("Running database manager");

        if (!DatabaseManager::isDatabaseSetup()) {
            startupErrorMessage("The database you have specified in config.lua is empty, please import the schema.sql to your database.");
            return;
        }
        g_databaseTasks.start();

        DatabaseManager::updateDatabase();

        if (getBoolean(ConfigManager::OPTIMIZE_DATABASE) && !DatabaseManager::optimizeTables()) {
            logger.warn("No tables were optimized.");
        }

        if (!verifyReputationEconomySchema()) {
            startupErrorMessage("Reputation/Economy schema verification failed. See logs for details.");
            return;
        }
        StartupProbe::mark("migrations");

        //load vocations
        logger.info("Loading vocations");
        if (!g_vocations.loadFromXml()) {
            startupErrorMessage("Unable to load vocations!");
            return;
        }

        StartupProbe::mark("vocations");

        // load item data
        logger.info("Loading items...");
        if (!Item::items.loadFromOtb("data/items/items.otb")) {
            startupErrorMessage("Unable to load items (OTB)!");
            return;
        }

        logger.info(fmt::format("OTB v{:d}.{:d}.{:d}", Item::items.majorVersion, Item::items.minorVersion, Item::items.buildNumber));

        if (!Item::items.loadFromXml()) {
            startupErrorMessage("Unable to load items (XML)!");
            return;
        }

        StartupProbe::mark("items");

        logger.info("Loading script systems");
        if (!ScriptingManager::getInstance().loadScriptSystems()) {
            startupErrorMessage("Failed to load script systems");
            return;
        }

        logger.info("Loading lua scripts");
        if (!g_scripts->loadScripts("scripts", false, false)) {
            startupErrorMessage("Failed to load lua scripts");
            return;
        }

        StartupProbe::mark("scripts_core");

        if (!reputationEnabled) {
            logger.info("Reputation system disabled by config");
        }
        if (!economyEnabled) {
            logger.info("Economy system disabled by config");
        }
        StartupProbe::mark("rep_eco");

        logger.info("Loading monsters");
        if (!g_monsters.loadFromXml()) {
            startupErrorMessage("Unable to load monsters!");
            return;
        }

        logger.info("Loading lua monsters");
        if (!g_scripts->loadScripts("monster", false, false)) {
            startupErrorMessage("Failed to load lua monsters");
            return;
        }

        logger.info("Loading outfits");
        if (!Outfits::getInstance().loadFromXml()) {
            startupErrorMessage("Unable to load outfits!");
            return;
        }

        logger.info("Checking world type...");
        std::string worldType = boost::algorithm::to_lower_copy(getString(ConfigManager::WORLD_TYPE));
        if (worldType == "pvp") {
            g_game.setWorldType(WORLD_TYPE_PVP);
        }
        else if (worldType == "no-pvp") {
            g_game.setWorldType(WORLD_TYPE_NO_PVP);
        }
        else if (worldType == "pvp-enforced") {
            g_game.setWorldType(WORLD_TYPE_PVP_ENFORCED);
        }
        else {
            startupErrorMessage(fmt::format("Unknown world type: {:s}, valid world types are: pvp, no-pvp and pvp-enforced.", getString(ConfigManager::WORLD_TYPE)));
            return;
        }
        logger.info(fmt::format("World type: {}", boost::algorithm::to_upper_copy(worldType)));

        logger.info("Loading map");
        if (!g_game.loadMainMap(getString(ConfigManager::MAP_NAME))) {
            startupErrorMessage("Failed to load map");
            return;
        }

        logger.info("Initializing game state");
        g_game.setGameState(GAME_STATE_INIT);

        // Game client protocols
        services->add<ProtocolGame>(static_cast<uint16_t>(getNumber(ConfigManager::GAME_PORT)));
        services->add<ProtocolLogin>(static_cast<uint16_t>(getNumber(ConfigManager::LOGIN_PORT)));

        // OT protocols
        services->add<ProtocolStatus>(static_cast<uint16_t>(getNumber(ConfigManager::STATUS_PORT)));

        // Legacy login protocol
        services->add<ProtocolOld>(static_cast<uint16_t>(getNumber(ConfigManager::LOGIN_PORT)));

        RentPeriod_t rentPeriod;
        std::string strRentPeriod = boost::algorithm::to_lower_copy(getString(ConfigManager::HOUSE_RENT_PERIOD));

        if (strRentPeriod == "yearly") {
            rentPeriod = RENTPERIOD_YEARLY;
        }
        else if (strRentPeriod == "weekly") {
            rentPeriod = RENTPERIOD_WEEKLY;
        }
        else if (strRentPeriod == "monthly") {
            rentPeriod = RENTPERIOD_MONTHLY;
        }
        else if (strRentPeriod == "daily") {
            rentPeriod = RENTPERIOD_DAILY;
        }
        else {
            rentPeriod = RENTPERIOD_NEVER;
        }

        g_game.map.houses.payHouses(rentPeriod);

        IOMarket::checkExpiredOffers();
        IOMarket::getInstance().updateStatistics();

        StartupProbe::mark("ready");
        logger.info("Loaded all modules, server starting up...");

#ifndef _WIN32
        if (getuid() == 0 || geteuid() == 0) {
            logger.warn(fmt::format("Warning: {} has been executed as root user, please consider running it as a normal user.", STATUS_SERVER_NAME));
        }
#endif

        g_game.start(services);
        g_game.setGameState(GAME_STATE_NORMAL);
#ifdef WITH_PYTHON
        if (getBoolean(ConfigManager::PYTHON_ENABLED) && PythonEngine::instance().isReady()) {
            PythonEngine::instance().onServerStart();
        }
#endif
        StartupProbe::mark(nullptr);
        g_loaderSignal.notify_all();
    }

    [[noreturn]] void badAllocationHandler() {
        // Use functions that only use stack allocation
        puts("Allocation failed, server out of memory.\nDecrease the size of your map or compile in 64 bits mode.\n");
        getchar();
        exit(-1);
    }

} // namespace

bool startServer() {
    // Setup bad allocation handler
    std::set_new_handler(badAllocationHandler);

    ServiceManager serviceManager;

    g_dispatcher.start();
    g_scheduler.start();

    g_dispatcher.addTask([services = &serviceManager]() { mainLoader(services); });

    g_loaderSignal.wait(g_loaderUniqueLock);

    bool servicesRunning = serviceManager.is_running();
    if (servicesRunning) {
        Logger::instance().info(fmt::format("{} Server Online!", getString(ConfigManager::SERVER_NAME)));
        serviceManager.run();
    }
    else {
        Logger::instance().error("No services running. The server is NOT online.");
        g_scheduler.shutdown();
        g_databaseTasks.shutdown();
        g_dispatcher.shutdown();
    }

#ifdef WITH_PYTHON
    if (PythonEngine::instance().isReady()) {
        PythonEngine::instance().onServerStop();
        PythonEngine::instance().shutdown();
    }
#endif

    g_scheduler.join();
    g_databaseTasks.join();
    g_dispatcher.join();

    return servicesRunning && !g_startupFailed.load(std::memory_order_relaxed);
}

void printServerVersion() {
#if defined(GIT_RETRIEVED_STATE) && GIT_RETRIEVED_STATE
    Logger::instance().info(fmt::format("{} - Version {}", STATUS_SERVER_NAME, GIT_DESCRIBE));
    Logger::instance().info(fmt::format("Git SHA1 {} dated {}", GIT_SHORT_SHA1, GIT_COMMIT_DATE_ISO8601));
#if GIT_IS_DIRTY
    Logger::instance().warn("*** DIRTY - NOT OFFICIAL RELEASE ***");
#endif
#else
    Logger::instance().info(fmt::format("{} - Version {}", STATUS_SERVER_NAME, STATUS_SERVER_VERSION));
#endif
    Logger::instance().info(fmt::format("Compiled with {}", BOOST_COMPILER));

    std::string platform;
#if defined(__amd64__) || defined(_M_X64)
    platform = "x64";
#elif defined(__i386__) || defined(_M_IX86) || defined(_X86_)
    platform = "x86";
#elif defined(__arm__)
    platform = "ARM";
#else
    platform = "unknown";
#endif
    Logger::instance().info(fmt::format("Compiled on {} {} for platform {}", __DATE__, __TIME__, platform));
#if defined(LUAJIT_VERSION)
    Logger::instance().info(fmt::format("Linked with {} for Lua support", LUAJIT_VERSION));
#else
    Logger::instance().info(fmt::format("Linked with {} for Lua support", LUA_RELEASE));
#endif
    Logger::instance().info(fmt::format("A server developed by {}", STATUS_SERVER_DEVELOPERS));
    Logger::instance().info("Visit our forum for updates, support, and resources: https://otland.net/.");
}