// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"

#include "database.h"

#include "configmanager.h"
#include "common/diagnostics.h"
#include "utils/Logger.h"

#include <mysql/errmsg.h>

#include <algorithm>
#include <array>
#include <atomic>
#include <chrono>
#include <cctype>
#include <string>
#include <string_view>

#include <fmt/format.h>

namespace {

        constexpr std::array<std::string_view, 8> kRepEcoTables = {
                "factions",
                "npc_factions",
                "player_faction_reputation",
                "player_faction_reputation_log",
                "faction_economy",
                "faction_economy_history",
                "faction_economy_ledger",
                "faction_market_cursor",
        };

        constexpr std::chrono::milliseconds kSlowQueryThreshold{1000};
        constexpr std::chrono::seconds kTransactionWarningThreshold{5};

        std::atomic<bool> g_transactionActive{false};
        std::atomic<bool> g_transactionWarned{false};
        std::atomic<int64_t> g_transactionStartMs{0};

        int64_t toMilliseconds(const std::chrono::steady_clock::time_point& tp) {
                return std::chrono::duration_cast<std::chrono::milliseconds>(tp.time_since_epoch()).count();
        }

        void beginTransactionTimer() {
                g_transactionActive.store(true, std::memory_order_relaxed);
                g_transactionWarned.store(false, std::memory_order_relaxed);
                g_transactionStartMs.store(toMilliseconds(std::chrono::steady_clock::now()), std::memory_order_relaxed);
        }

        void endTransactionTimer() {
                g_transactionActive.store(false, std::memory_order_relaxed);
                g_transactionWarned.store(false, std::memory_order_relaxed);
        }

        std::string toLowerLimited(std::string_view text, std::size_t limit) {
                std::string lower;
                const auto count = std::min<std::size_t>(text.size(), limit);
                lower.reserve(count);
                for (std::size_t index = 0; index < count; ++index) {
                        lower.push_back(static_cast<char>(std::tolower(static_cast<unsigned char>(text[index]))));
                }
                return lower;
        }

        bool shouldTraceRepEco(std::string_view query) {
                if (!diagnostics::isSqlTraceEnabled()) {
                        return false;
                }

                const auto lowered = toLowerLimited(query, 4096);
                for (const auto table : kRepEcoTables) {
                        if (lowered.find(table) != std::string::npos) {
                                return true;
                        }
                }
                return false;
        }

        std::string sanitizeSql(std::string_view sql) {
                std::string sanitized;
                sanitized.reserve(std::min<std::size_t>(sql.size(), 120));
                std::size_t appended = 0;
                bool previousSpace = false;
                for (char ch : sql) {
                        if (appended >= 120) {
                                break;
                        }
                        unsigned char uch = static_cast<unsigned char>(ch);
                        if (std::isspace(uch)) {
                                if (!previousSpace) {
                                        sanitized.push_back(' ');
                                        ++appended;
                                        previousSpace = true;
                                }
                        } else {
                                sanitized.push_back(ch);
                                ++appended;
                                previousSpace = false;
                        }
                }

                return sanitized;
        }

        void checkTransactionDuration() {
                if (!g_transactionActive.load(std::memory_order_relaxed) || g_transactionWarned.load(std::memory_order_relaxed)) {
                        return;
                }

                const auto nowMs = toMilliseconds(std::chrono::steady_clock::now());
                const auto startMs = g_transactionStartMs.load(std::memory_order_relaxed);
                if (nowMs - startMs >= std::chrono::duration_cast<std::chrono::milliseconds>(kTransactionWarningThreshold).count()) {
                        g_transactionWarned.store(true, std::memory_order_relaxed);
                        Logger::instance().warn(fmt::format("SQL[rep_eco] long-running transaction ({} ms without commit)", nowMs - startMs));
                        Logger::instance().flush();
                }
        }

        void logSqlTrace(std::string_view sql, std::chrono::milliseconds duration, uint64_t rows, bool isSelect) {
                const std::string message = fmt::format(
                        "SQL[rep_eco] ({} ms, {} {}) {}",
                        duration.count(),
                        rows,
                        isSelect ? "rows" : "rows affected",
                        sanitizeSql(sql));

                if (duration > kSlowQueryThreshold) {
                        Logger::instance().warn(message);
                } else {
                        Logger::instance().info(message);
                }
                Logger::instance().flush();
                checkTransactionDuration();
        }

} // namespace

static detail::Mysql_ptr connectToDatabase(const bool retryIfError) {
	bool isFirstAttemptToConnect = true;

	retry:
	if (!isFirstAttemptToConnect) {
		std::this_thread::sleep_for(std::chrono::seconds(1));
	}
	isFirstAttemptToConnect = false;

	// connection handle initialization
	detail::Mysql_ptr handle{mysql_init(nullptr)};

	// config to disable ssl
#ifdef MARIADB_VERSION_ID
	bool ssl_enforce = false;
	bool ssl_verify = false;
#endif

	// cant connection handle
	if (!handle) {
		std::cout << std::endl << "Failed to initialize MySQL connection handle." << std::endl;
		goto error;
	}

	// disable ssl
#ifdef MARIADB_VERSION_ID
	mysql_options(handle.get(), MYSQL_OPT_SSL_ENFORCE, &ssl_enforce);
	mysql_options(handle.get(), MYSQL_OPT_SSL_VERIFY_SERVER_CERT, &ssl_verify);
	mysql_ssl_set(handle.get(), nullptr, nullptr, nullptr, nullptr, nullptr);
#endif

	// connects to database
	if (!mysql_real_connect(handle.get(), getString(ConfigManager::MYSQL_HOST).c_str(), getString(ConfigManager::MYSQL_USER).c_str(), getString(ConfigManager::MYSQL_PASS).c_str(), getString(ConfigManager::MYSQL_DB).c_str(), getNumber(ConfigManager::SQL_PORT), getString(ConfigManager::MYSQL_SOCK).c_str(), 0)) {
		std::cout << std::endl << "MySQL Error Message: " << mysql_error(handle.get()) << std::endl;
		goto error;
	}
	return handle;

	error:
	if (retryIfError) {
		goto retry;
	}

	return nullptr;
}

static bool isLostConnectionError(const unsigned error) {
	return error == CR_SERVER_LOST || error == CR_SERVER_GONE_ERROR || error == CR_CONN_HOST_ERROR || error == 1053 /*ER_SERVER_SHUTDOWN*/ || error == CR_CONNECTION_ERROR;
}

static bool executeQuery(detail::Mysql_ptr& handle, std::string_view query, const bool retryIfLostConnection) {
	while (mysql_real_query(handle.get(), query.data(), query.length()) != 0) {
		std::cout << "[Error - mysql_real_query] Query: " << query.substr(0, 256) << std::endl << "Message: " << mysql_error(handle.get()) << std::endl;
		const unsigned error = mysql_errno(handle.get());
		if (!isLostConnectionError(error) || !retryIfLostConnection) {
			return false;
		}
		handle = connectToDatabase(true);
	}

	return true;
}

bool Database::connect() {
	auto newHandle = connectToDatabase(false);
	if (!newHandle) {
		return false;
	}

	handle = std::move(newHandle);
	DBResult_ptr result = storeQuery("SHOW VARIABLES LIKE 'max_allowed_packet'");
	if (result) {
		maxPacketSize = result->getNumber<uint64_t>("Value");
	}

	return true;
}

bool Database::beginTransaction() {
        databaseLock.lock();

        const bool result = executeQuery("START TRANSACTION");
        retryQueries = !result;
        if (!result) {
                databaseLock.unlock();
        } else {
                beginTransactionTimer();
        }

        return result;
}

bool Database::rollback() {
        const bool result = executeQuery("ROLLBACK");
        retryQueries = true;
        databaseLock.unlock();
        if (result) {
                endTransactionTimer();
        }

        return result;
}

bool Database::commit() {
        const bool result = executeQuery("COMMIT");
        retryQueries = true;
        databaseLock.unlock();
        if (result) {
                endTransactionTimer();
        }

        return result;
}

bool Database::executeQuery(const std::string& query) {
        std::lock_guard<std::recursive_mutex> lockGuard(databaseLock);
        const bool traceThis = shouldTraceRepEco(query);
        std::chrono::steady_clock::time_point start;
        if (traceThis) {
                start = std::chrono::steady_clock::now();
        }

        auto result = ::executeQuery(handle, query, retryQueries);

        my_ulonglong affectedRows = 0;
        if (result) {
                affectedRows = mysql_affected_rows(handle.get());
        }

        // executeQuery can be called with command that produces result (e.g. SELECT)
        // we have to store that result, even though we do not need it, otherwise handle will get blocked
        auto mysql_res = mysql_store_result(handle.get());
        mysql_free_result(mysql_res);

        if (traceThis) {
                const auto duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - start);
                logSqlTrace(query, duration, static_cast<uint64_t>(affectedRows), false);
        }

        return result;
}

DBResult_ptr Database::storeQuery(std::string_view query) {
        std::lock_guard<std::recursive_mutex> lockGuard(databaseLock);

        const bool traceThis = shouldTraceRepEco(query);
        std::chrono::steady_clock::time_point start;
        if (traceThis) {
                start = std::chrono::steady_clock::now();
        }

retry:
        if (!::executeQuery(handle, query, retryQueries) && !retryQueries) {
                if (traceThis) {
                        const auto duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - start);
                        logSqlTrace(query, duration, 0, true);
                }
                return nullptr;
        }

        // we should call that every time as someone would call executeQuery('SELECT...')
        // as it is described in MySQL manual: "it doesn't hurt" :P
        detail::MysqlResult_ptr res{mysql_store_result(handle.get())};
        if (!res) {
                std::cout << "[Error - mysql_store_result] Query: " << query << std::endl << "Message: " << mysql_error(handle.get()) << std::endl;
                const unsigned error = mysql_errno(handle.get());
                if (!isLostConnectionError(error) || !retryQueries) {
                        if (traceThis) {
                                const auto duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - start);
                                logSqlTrace(query, duration, 0, true);
                        }
                        return nullptr;
                }
                goto retry;
        }

        const uint64_t rowCount = static_cast<uint64_t>(mysql_num_rows(res.get()));
        // retrieving results of query
        DBResult_ptr result = std::make_shared<DBResult>(std::move(res));
        if (!result->hasNext()) {
                if (traceThis) {
                        const auto duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - start);
                        logSqlTrace(query, duration, rowCount, true);
                }
                return nullptr;
        }
        if (traceThis) {
                const auto duration = std::chrono::duration_cast<std::chrono::milliseconds>(std::chrono::steady_clock::now() - start);
                logSqlTrace(query, duration, rowCount, true);
        }
        return result;
}

std::string Database::escapeBlob(const char* s, uint32_t length) const {
	// the worst case is 2n + 1
	size_t maxLength = (length * 2) + 1;

	std::string escaped;
	escaped.reserve(maxLength + 2);
	escaped.push_back('\'');

	if (length != 0) {
		char* output = new char[maxLength];
		mysql_real_escape_string(handle.get(), output, s, length);
		escaped.append(output);
		delete[] output;
	}

	escaped.push_back('\'');
	return escaped;
}

DBResult::DBResult(detail::MysqlResult_ptr&& res) : handle {std::move(res)} {
	size_t i = 0;

	MYSQL_FIELD* field = mysql_fetch_field(handle.get());
	while (field) {
		listNames[field->name] = i++;
		field = mysql_fetch_field(handle.get());
	}

	row = mysql_fetch_row(handle.get());
}

std::string_view DBResult::getString(std::string_view column) const {
	auto it = listNames.find(column);
	if (it == listNames.end()) {
		std::cout << "[Error - DBResult::getString] Column '" << column << "' does not exist in result set." << std::endl;
		return {};
	}

	if (!row[it->second]) {
		return {};
	}

	auto size = mysql_fetch_lengths(handle.get())[it->second];
	return {row[it->second], size};
}

bool DBResult::hasNext() const {
	return row;
}

bool DBResult::next() {
	row = mysql_fetch_row(handle.get());
	return row;
}

DBInsert::DBInsert(std::string query) : query(std::move(query)) {
	this->length = this->query.length();
}

bool DBInsert::addRow(const std::string& row) {
	// adds new row to buffer
	const size_t rowLength = row.length();
	length += rowLength;
	if (length > Database::getInstance().getMaxPacketSize() && !execute()) {
		return false;
	}

	if (values.empty()) {
		values.reserve(rowLength + 2);
		values.push_back('(');
		values.append(row);
		values.push_back(')');
	} else {
		values.reserve(values.length() + rowLength + 3);
		values.push_back(',');
		values.push_back('(');
		values.append(row);
		values.push_back(')');
	}
	return true;
}

bool DBInsert::addRow(std::ostringstream& row) {
	bool ret = addRow(row.str());
	row.str(std::string());
	return ret;
}

bool DBInsert::execute() {
	if (values.empty()) {
		return true;
	}

	// executes buffer
	bool res = Database::getInstance().executeQuery(query + values);
	values.clear();
	length = query.length();
	return res;
}