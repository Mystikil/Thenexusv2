#pragma once

#include <type_traits>

#include <fmt/format.h>

#include "configmanager.h"

namespace fmt {

template <>
struct formatter<ConfigManager::boolean_config_t> : formatter<std::underlying_type_t<ConfigManager::boolean_config_t>> {
        template <typename FormatContext>
        auto format(const ConfigManager::boolean_config_t& v, FormatContext& ctx) const {
                using underlying = std::underlying_type_t<ConfigManager::boolean_config_t>;
                return formatter<underlying>::format(static_cast<underlying>(v), ctx);
        }
};

template <>
struct formatter<ConfigManager::integer_config_t> : formatter<std::underlying_type_t<ConfigManager::integer_config_t>> {
        template <typename FormatContext>
        auto format(const ConfigManager::integer_config_t& v, FormatContext& ctx) const {
                using underlying = std::underlying_type_t<ConfigManager::integer_config_t>;
                return formatter<underlying>::format(static_cast<underlying>(v), ctx);
        }
};

template <>
struct formatter<ConfigManager::string_config_t> : formatter<std::underlying_type_t<ConfigManager::string_config_t>> {
        template <typename FormatContext>
        auto format(const ConfigManager::string_config_t& v, FormatContext& ctx) const {
                using underlying = std::underlying_type_t<ConfigManager::string_config_t>;
                return formatter<underlying>::format(static_cast<underlying>(v), ctx);
        }
};

} // namespace fmt

