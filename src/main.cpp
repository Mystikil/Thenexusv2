#include "otpch.h"

#include "configmanager.h"
#include "otserv.h"
#include "tools.h"
#include "utils/CrashGuard.h"
#include "utils/Logger.h"
#include "utils/Path.h"
#include "utils/StartupProbe.h"
#include "common/diagnostics.h"
#include "scripting/LuaErrorWrap.h"

#include <chrono>
#include <cstdlib>

namespace {

        bool g_traceStartupRequested = false;

        void setTraceStartupEnvFlag(bool enabled) {
#if defined(_WIN32)
                _putenv_s("NEXUS_TRACE_STARTUP", enabled ? "1" : "0");
#else
                if (enabled) {
                        setenv("NEXUS_TRACE_STARTUP", "1", 1);
                } else {
                        unsetenv("NEXUS_TRACE_STARTUP");
                }
#endif
        }

} // namespace

static bool argumentsHandler(const std::vector<std::string_view>& args) {
        for (const auto& arg : args) {
                if (arg == "--help") {
                        std::clog << "Usage:\n"
                                     "\n"
			             "\t--config=$1\t\tAlternate configuration file path.\n"
			             "\t--ip=$1\t\t\tIP address of the server.\n"
			             "\t\t\t\tShould be equal to the global IP.\n"
			             "\t--login-port=$1\tPort for login server to listen on.\n"
			             "\t--game-port=$1\tPort for game server to listen on.\n";
			return false;
                } else if (arg == "--version") {
                        printServerVersion();
                        return false;
                } else if (arg == "--trace-startup") {
                        g_traceStartupRequested = true;
                        diagnostics::setTraceStartupEnabled(true);
                        StartupProbe::setWatchdogThreshold(std::chrono::milliseconds(5000));
                        diagnostics::setSqlTraceEnabled(true);
                }

		auto tmp = explodeString(arg, "=");

		if (tmp[0] == "--config")
			ConfigManager::setString(ConfigManager::CONFIG_FILE, tmp[1]);
		else if (tmp[0] == "--ip")
			ConfigManager::setString(ConfigManager::IP, tmp[1]);
		else if (tmp[0] == "--login-port")
			ConfigManager::setNumber(ConfigManager::LOGIN_PORT, std::stoi(tmp[1].data()));
		else if (tmp[0] == "--game-port")
			ConfigManager::setNumber(ConfigManager::GAME_PORT, std::stoi(tmp[1].data()));
	}

	return true;
}

int main(int argc, const char** argv) {
        Logger::instance().setLogFile(makePath(L"logs/server.log"));
        Logger::instance().setConsole(true);
        Logger::instance().setLevel(LogLevel::Info);
        Logger::instance().info("Nexus Server starting...");
        Logger::instance().info("Build: " __DATE__ " " __TIME__);

        InstallCrashHandlers();
        StartupProbe::initialize();

        diagnostics::setTraceStartupEnabled(false);
        diagnostics::setSqlTraceEnabled(false);
        StartupProbe::setWatchdogThreshold(std::chrono::milliseconds(10000));

        std::vector<std::string_view> args(argv, argv + argc);
        if (!argumentsHandler(args)) {
                return 1;
        }

        if (g_traceStartupRequested) {
                Logger::instance().setLevel(LogLevel::Debug);
        } else {
                StartupProbe::setWatchdogThreshold(std::chrono::milliseconds(10000));
        }

        setTraceStartupEnvFlag(g_traceStartupRequested);

        if (!startServer()) {
                Logger::instance().fatal("Server failed to start. See logs for details.");
                StartupProbe::shutdown();
                return EXIT_FAILURE;
        }

        Logger::instance().info("Server shutdown complete.");
        StartupProbe::shutdown();
        return EXIT_SUCCESS;
}