// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"

#include "script.h"

#include "configmanager.h"
#include "utils/Logger.h"

#include <fmt/format.h>

extern LuaEnvironment g_luaEnvironment;

Scripts::Scripts() :
	scriptInterface("Scripts Interface") {
	scriptInterface.initState();
}

Scripts::~Scripts() {
	scriptInterface.reInitState();
}

bool Scripts::loadScripts(std::string folderName, bool isLib, bool reload) {
	namespace fs = std::filesystem;

        const auto dir = fs::current_path() / "data" / folderName;
        if (!fs::exists(dir) || !fs::is_directory(dir)) {
                Logger::instance().warn(fmt::format("[Warning - Scripts::loadScripts] Cannot load folder '{}'", folderName));
                return false;
        }

	fs::recursive_directory_iterator endit;
	std::vector<fs::path> v;
	std::string disable = ("#");
	for (fs::recursive_directory_iterator it(dir); it != endit; ++it) {
		auto fn = it->path().parent_path().filename();
		if (fn == "lib" && !isLib) {
			continue;
		}
		if (fs::is_regular_file(*it) && it->path().extension() == ".lua") {
			size_t found = it->path().filename().string().find(disable);
			if (found != std::string::npos) {
                                if (getBoolean(ConfigManager::SCRIPTS_CONSOLE_LOGS)) {
                                        Logger::instance().info(fmt::format("{} [disabled]", it->path().filename().string()));
                                }
                                continue;
                        }
                        v.push_back(it->path());
		}
	}
	sort(v.begin(), v.end());
	std::string redir;
	for (auto it = v.begin(); it != v.end(); ++it) {
		const std::string scriptFile = it->string();
		if (!isLib) {
                        if (redir.empty() || redir != it->parent_path().string()) {
                                auto p = fs::path(it->relative_path());
                                if (getBoolean(ConfigManager::SCRIPTS_CONSOLE_LOGS)) {
                                        Logger::instance().info(fmt::format("[{}]", p.parent_path().filename().string()));
                                }
                                redir = it->parent_path().string();
                        }
                }

                if (scriptInterface.loadFile(scriptFile) == -1) {
                        const std::string error = scriptInterface.getLastLuaError();
                        Logger::instance().error(fmt::format("Lua error while loading {}: {}", scriptFile, error));
                        Logger::instance().fatal(fmt::format("Script load failed: {}", scriptFile));
                        return false;
                }

                if (getBoolean(ConfigManager::SCRIPTS_CONSOLE_LOGS)) {
                        if (!reload) {
                                Logger::instance().info(fmt::format("{} [loaded]", it->filename().string()));
                        } else {
                                Logger::instance().info(fmt::format("{} [reloaded]", it->filename().string()));
                        }
                }
        }

        return true;
}