#include "otpch.h"

#include "Logger.h"

#include <chrono>
#include <ctime>
#include <iomanip>
#include <iostream>
#include <sstream>
#include <string>
#include <string_view>
#include <system_error>

namespace {
std::tm safeLocalTime(std::time_t time)
{
    std::tm result{};
#if defined(_WIN32)
    localtime_s(&result, &time);
#else
    localtime_r(&time, &result);
#endif
    return result;
}
}

Logger::Logger()
    : logFilePath_("logs/server.log"),
      maxBytes_(5 * 1024 * 1024),
      maxFiles_(5),
      level_(LogLevel::Info),
      consoleEnabled_(true)
{
    openLogFileUnlocked();
}

Logger& Logger::instance()
{
    static Logger instance;
    return instance;
}

void Logger::setLogFile(std::filesystem::path const& path, std::uint64_t maxBytes, std::uint64_t maxFiles)
{
    std::lock_guard<std::mutex> lock(mutex_);

    logStream_.close();
    logStream_.clear();

    logFilePath_ = path.empty() ? std::filesystem::path("logs/server.log") : path;
    maxBytes_ = maxBytes;
    maxFiles_ = maxFiles;

    openLogFileUnlocked();
}

void Logger::setConsole(bool enabled)
{
    std::lock_guard<std::mutex> lock(mutex_);
    consoleEnabled_ = enabled;
}

void Logger::setLevel(LogLevel level)
{
    std::lock_guard<std::mutex> lock(mutex_);
    level_ = level;
}

void Logger::trace(std::string_view message)
{
    logMessage(LogLevel::Trace, message);
}

void Logger::debug(std::string_view message)
{
    logMessage(LogLevel::Debug, message);
}

void Logger::info(std::string_view message)
{
    logMessage(LogLevel::Info, message);
}

void Logger::warn(std::string_view message)
{
    logMessage(LogLevel::Warn, message);
}

void Logger::error(std::string_view message)
{
    logMessage(LogLevel::Error, message);
}

void Logger::fatal(std::string_view message)
{
    logMessage(LogLevel::Fatal, message);
}

void Logger::flush()
{
    std::lock_guard<std::mutex> lock(mutex_);

    if (logStream_.is_open()) {
        logStream_.flush();
    }

    if (consoleEnabled_) {
        std::clog << std::flush;
    }
}

void Logger::logMessage(LogLevel level, std::string_view message)
{
    if (static_cast<int>(level) < static_cast<int>(level_)) {
        return;
    }

    auto now = std::chrono::system_clock::now();
    auto nowTimeT = std::chrono::system_clock::to_time_t(now);
    auto tm = safeLocalTime(nowTimeT);

    std::ostringstream builder;
    builder << std::put_time(&tm, "%Y-%m-%d %H:%M:%S")
            << " [" << levelToString(level) << "] "
            << message;
    auto formatted = builder.str();

    std::lock_guard<std::mutex> lock(mutex_);

    rotateIfNeededUnlocked(formatted.size() + 1);

    if (!logStream_.is_open()) {
        openLogFileUnlocked();
    }

    if (logStream_.is_open()) {
        logStream_ << formatted << '\n';
        logStream_.flush();
    }

    if (consoleEnabled_) {
        std::clog << formatted << std::endl;
    }
}

void Logger::rotateIfNeededUnlocked(std::size_t upcomingBytes)
{
    if (maxBytes_ == 0 || maxFiles_ == 0 || logFilePath_.empty()) {
        return;
    }

    std::error_code ec;
    std::uint64_t currentSize = 0;
    if (std::filesystem::exists(logFilePath_, ec) && !ec) {
        std::error_code sizeEc;
        currentSize = std::filesystem::file_size(logFilePath_, sizeEc);
        if (sizeEc) {
            return;
        }
    }

    if (currentSize + static_cast<std::uint64_t>(upcomingBytes) <= maxBytes_) {
        return;
    }

    logStream_.close();

    for (std::uint64_t index = maxFiles_; index > 0; --index) {
        auto target = rotationPath(index);
        auto source = (index == 1) ? logFilePath_ : rotationPath(index - 1);

        std::error_code removeEc;
        std::filesystem::remove(target, removeEc);

        std::error_code existsEc;
        if (std::filesystem::exists(source, existsEc) && !existsEc) {
            std::error_code renameEc;
            std::filesystem::rename(source, target, renameEc);
        }
    }

    openLogFileUnlocked();
}

void Logger::openLogFileUnlocked()
{
    if (logFilePath_.empty()) {
        return;
    }

    auto parent = logFilePath_.parent_path();
    if (!parent.empty()) {
        std::error_code ec;
        std::filesystem::create_directories(parent, ec);
    }

    logStream_.open(logFilePath_, std::ios::app | std::ios::out);
}

std::filesystem::path Logger::rotationPath(std::uint64_t index) const
{
    auto fileName = logFilePath_.filename().string();
    auto parent = logFilePath_.parent_path();
    return parent / (fileName + "." + std::to_string(index));
}

char const* Logger::levelToString(LogLevel level)
{
    switch (level) {
    case LogLevel::Trace:
        return "TRACE";
    case LogLevel::Debug:
        return "DEBUG";
    case LogLevel::Info:
        return "INFO";
    case LogLevel::Warn:
        return "WARN";
    case LogLevel::Error:
        return "ERROR";
    case LogLevel::Fatal:
        return "FATAL";
    }
    return "UNKNOWN";
}

