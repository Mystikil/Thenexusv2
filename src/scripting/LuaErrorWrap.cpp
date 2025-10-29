#include "otpch.h"

#include "scripting/LuaErrorWrap.h"

#include <lua.hpp>

#include <string>

#include "utils/Logger.h"

#ifndef LUA_OK
#define LUA_OK 0
#endif

int pushTraceback(lua_State* L)
{
    const char* message = nullptr;
    if (lua_isstring(L, 1)) {
        message = lua_tostring(L, 1);
    } else if (!lua_isnoneornil(L, 1)) {
        if (luaL_callmeta(L, 1, "__tostring") && lua_isstring(L, -1)) {
            message = lua_tostring(L, -1);
        } else {
            message = "non-string error";
        }
    }

#if LUA_VERSION_NUM >= 502
    luaL_traceback(L, L, message, 1);
#else
    if (!message) {
        message = "";
    }

    lua_getglobal(L, "debug");
    if (!lua_istable(L, -1)) {
        lua_pop(L, 1);
        lua_pushstring(L, message);
        return 1;
    }

    lua_getfield(L, -1, "traceback");
    if (!lua_isfunction(L, -1)) {
        lua_pop(L, 2);
        lua_pushstring(L, message);
        return 1;
    }

    lua_pushstring(L, message);
    lua_pushinteger(L, 2);
    lua_call(L, 2, 1);
    lua_remove(L, -2);
#endif
    return 1;
}

bool pcallWithTrace(lua_State* L, int nargs, int nresults, const std::string& context)
{
    int base = lua_gettop(L) - nargs;
    lua_pushcfunction(L, pushTraceback);
    lua_insert(L, base);

    int status = lua_pcall(L, nargs, nresults, base);
    lua_remove(L, base);

    if (status != LUA_OK) {
        const char* err = lua_tostring(L, -1);
        std::string message = err ? err : "unknown error";
        Logger::instance().error("Lua error (" + context + "): " + message);
        lua_pop(L, 1);
        return false;
    }

    return true;
}

int luaPanic(lua_State* L)
{
    const char* msg = lua_tostring(L, -1);
    std::string message = msg ? msg : "unknown error";
    Logger::instance().fatal("Lua panic: " + message);
    return 0;
}

