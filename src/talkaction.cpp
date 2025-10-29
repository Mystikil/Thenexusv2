// Copyright 2023 The Forgotten Server Authors. All rights reserved.
// Use of this source code is governed by the GPL-2.0 License that can be found in the LICENSE file.

#include "otpch.h"

#include "player.h"
#include "talkaction.h"
#include "configmanager.h"

#ifdef WITH_PYTHON
#include "python/PythonEngine.h"
#endif

TalkActions::TalkActions()
	: scriptInterface("TalkAction Interface") {
	scriptInterface.initState();
}

TalkActions::~TalkActions() {
	clear(false);
}

void TalkActions::clear(bool fromLua) {
	for (auto it = talkActions.begin(); it != talkActions.end();) {
		if (fromLua == it->second.fromLua) {
			it = talkActions.erase(it);
		} else {
			++it;
		}
	}

	reInitState(fromLua);
}

LuaScriptInterface& TalkActions::getScriptInterface() {
	return scriptInterface;
}

Event_ptr TalkActions::getEvent(const std::string& nodeName) {
	if (!caseInsensitiveEqual(nodeName, "talkaction")) {
		return nullptr;
	}
	return Event_ptr(new TalkAction(&scriptInterface));
}

bool TalkActions::registerEvent(Event_ptr event, const pugi::xml_node&) {
	TalkAction_ptr talkAction{static_cast<TalkAction*>(event.release())}; // event is guaranteed to be a TalkAction
	std::vector<std::string> words = talkAction->getWordsMap();

	for (size_t i = 0; i < words.size(); i++) {
		if (i == words.size() - 1) {
			talkActions.emplace(words[i], std::move(*talkAction));
		} else {
			talkActions.emplace(words[i], *talkAction);
		}
	}

	return true;
}

bool TalkActions::registerLuaEvent(TalkAction* event) {
	TalkAction_ptr talkAction{ event };
	std::vector<std::string> words = talkAction->getWordsMap();

	for (size_t i = 0; i < words.size(); i++) {
		if (i == words.size() - 1) {
			talkActions.emplace(words[i], std::move(*talkAction));
		} else {
			talkActions.emplace(words[i], *talkAction);
		}
	}

	return true;
}

TalkActionResult_t TalkActions::playerSaySpell(Player* player, SpeakClasses type, const std::string& words) const {
#ifdef WITH_PYTHON
        if (caseInsensitiveStartsWith(words, "!py")) {
                if (words.size() > 3 && !std::isspace(static_cast<unsigned char>(words[3]))) {
                        // Command prefix matched but not an exact !py invocation; fall through to other talk actions.
                } else {
                        if (!player->isAccessPlayer()) {
                                player->sendTextMessage(MESSAGE_INFO_DESCR, "You are not allowed to use this command.");
                                return TALKACTION_BREAK;
                        }

                        if (!ConfigManager::getBoolean(ConfigManager::PYTHON_ENABLED)) {
                                player->sendTextMessage(MESSAGE_INFO_DESCR, "Python runtime is disabled.");
                                return TALKACTION_BREAK;
                        }

                        if (!PythonEngine::instance().isReady()) {
                                player->sendTextMessage(MESSAGE_INFO_DESCR, "Python runtime is not initialized.");
                                return TALKACTION_BREAK;
                        }

                        std::string command;
                        if (words.size() > 3) {
                                command = words.substr(3);
                                boost::algorithm::trim(command);
                        }

                        if (command.empty()) {
                                player->sendTextMessage(MESSAGE_INFO_DESCR, "Usage: !py reload | !py call <function> [args...]");
                                return TALKACTION_BREAK;
                        }

                        std::istringstream stream(command);
                        std::string subcommand;
                        stream >> subcommand;

                        if (boost::iequals(subcommand, "reload")) {
                                const bool ok = PythonEngine::instance().reload();
                                player->sendTextMessage(MESSAGE_INFO_DESCR, ok ? "Python scripts reloaded." : "Python reload failed.");
                                return TALKACTION_BREAK;
                        }

                        if (boost::iequals(subcommand, "call")) {
                                std::string functionName;
                                stream >> functionName;
                                if (functionName.empty()) {
                                        player->sendTextMessage(MESSAGE_INFO_DESCR, "Usage: !py call <function> [args...]");
                                        return TALKACTION_BREAK;
                                }

                                std::vector<std::string> args;
                                std::string arg;
                                while (stream >> arg) {
                                        args.emplace_back(arg);
                                }

                                const bool ok = PythonEngine::instance().call(functionName.c_str(), args);
                                player->sendTextMessage(MESSAGE_INFO_DESCR, ok ? "Python call succeeded." : "Python call failed.");
                                return TALKACTION_BREAK;
                        }

                        player->sendTextMessage(MESSAGE_INFO_DESCR, "Unknown subcommand. Usage: !py reload | !py call <function> [args...]");
                        return TALKACTION_BREAK;
                }
        }
#endif

        size_t wordsLength = words.length();
        for (auto it = talkActions.begin(); it != talkActions.end();) {
                const std::string& talkactionWords = it->first;
                if (!caseInsensitiveStartsWith(words, talkactionWords)) {
                        ++it;
			continue;
		}

		std::string param;
		if (wordsLength != talkactionWords.size()) {
			param = words.substr(talkactionWords.size());
			if (param.front() != ' ') {
				++it;
				continue;
			}
			boost::algorithm::trim_left(param);

			std::string separator = it->second.getSeparator();
			if (separator != " ") {
				if (!param.empty()) {
					if (param != separator) {
						++it;
						continue;
					} else {
						param.erase(param.begin());
					}
				}
			}
		}

		if (it->second.fromLua) {
			if (it->second.getNeedAccess() && !player->getGroup()->access) {
				return TALKACTION_CONTINUE;
			}

			if (player->getAccountType() < it->second.getRequiredAccountType()) {
				return TALKACTION_CONTINUE;
			}
		}

		if (it->second.executeSay(player, talkactionWords, param, type)) {
			return TALKACTION_CONTINUE;
		} else {
			return TALKACTION_BREAK;
		}
	}
	return TALKACTION_CONTINUE;
}

bool TalkAction::configureEvent(const pugi::xml_node& node) {
	pugi::xml_attribute wordsAttribute = node.attribute("words");
	if (!wordsAttribute) {
		std::cout << "[Error - TalkAction::configureEvent] Missing words for talk action or spell" << std::endl;
		return false;
	}

	pugi::xml_attribute separatorAttribute = node.attribute("separator");
	if (separatorAttribute) {
		separator = separatorAttribute.as_string();
	}

	for (auto word : explodeString(wordsAttribute.as_string(), ";")) {
		setWords(word);
	}
	return true;
}

bool TalkAction::executeSay(Player* player, const std::string& words, const std::string& param, SpeakClasses type) const {
	//onSay(player, words, param, type)
	if (!lua::reserveScriptEnv()) {
		std::cout << "[Error - TalkAction::executeSay] Call stack overflow" << std::endl;
		return false;
	}

	ScriptEnvironment* env = lua::getScriptEnv();
	env->setScriptId(scriptId, scriptInterface);

	lua_State* L = scriptInterface->getLuaState();

	scriptInterface->pushFunction(scriptId);

	lua::pushUserdata(L, player);
	lua::setMetatable(L, -1, "Player");

	lua::pushString(L, words);
	lua::pushString(L, param);
	lua_pushnumber(L, type);

	return scriptInterface->callFunction(4);
}