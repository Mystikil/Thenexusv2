#pragma once
#include <cstdint>
#include <string>
#include "position.h"  // defines Position

// forward declare enum class from Rank.hpp without including it
enum class RankTier : uint8_t;

class WorldPressureManager {
public:
    static WorldPressureManager& get();

    bool loadJson(const std::string& path, std::string& err);
    bool saveJson(const std::string& path, std::string& err) const;

    // bias in [-1..+1], used to tilt rank up/down
    double getPressureBias(const Position& pos, std::uint64_t partyKey) const;
    void   registerKill(const Position& pos, RankTier tier, std::uint64_t partyKey);
    void   decayTouched(std::uint64_t partyKey);

private:
    WorldPressureManager() = default;
};
