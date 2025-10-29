#include "otpch.h"

#include "utils/CrashGuard.h"

#include "utils/Logger.h"

#ifdef _WIN32
#include <Windows.h>
#include <dbghelp.h>
#pragma comment(lib, "DbgHelp.lib")
#endif

void InstallCrashHandlers() {
    Logger::instance().info("Crash handlers installed");
}
