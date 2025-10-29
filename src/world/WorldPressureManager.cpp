#include "otpch.h"  // MUST be first
#include "world/WorldPressureManager.hpp"
#include "monster/Rank.hpp" // for RankTier

static WorldPressureManager* g_wpm = nullptr;

WorldPressureManager& WorldPressureManager::get() {
    if (!g_wpm) g_wpm = new WorldPressureManager();
    return *g_wpm;
}

bool WorldPressureManager::loadJson(const std::string&, std::string& err) { err.clear(); return true; }
bool WorldPressureManager::saveJson(const std::string&, std::string& err) const { err.clear(); return true; }

double WorldPressureManager::getPressureBias(const Position&, std::uint64_t) const { return 0.0; }
void   WorldPressureManager::registerKill(const Position&, RankTier, std::uint64_t) {}
void   WorldPressureManager::decayTouched(std::uint64_t) {}
