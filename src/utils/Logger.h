#pragma once

#include <cstddef>
#include <cstdint>
#include <filesystem>
#include <fstream>
#include <mutex>
#include <string_view>

enum class LogLevel { Trace, Debug, Info, Warn, Error, Fatal };

class Logger {
public:
    static Logger& instance();

    void setLogFile(std::filesystem::path const& path, std::uint64_t maxBytes = 5 * 1024 * 1024, std::uint64_t maxFiles = 5);
    void setConsole(bool enabled);
    void setLevel(LogLevel level);
    void trace(std::string_view message);
    void debug(std::string_view message);
    void info(std::string_view message);
    void warn(std::string_view message);
    void error(std::string_view message);
    void fatal(std::string_view message);
    void flush();

private:
    Logger();
    void logMessage(LogLevel level, std::string_view message);
    void rotateIfNeededUnlocked(std::size_t upcomingBytes);
    void openLogFileUnlocked();
    std::filesystem::path rotationPath(std::uint64_t index) const;
    static char const* levelToString(LogLevel level);

    Logger(Logger const&) = delete;
    Logger(Logger&&) = delete;
    Logger& operator=(Logger const&) = delete;
    Logger& operator=(Logger&&) = delete;

    std::mutex mutex_;
    std::filesystem::path logFilePath_;
    std::ofstream logStream_;
    std::uint64_t maxBytes_;
    std::uint64_t maxFiles_;
    LogLevel level_;
    bool consoleEnabled_;
};

