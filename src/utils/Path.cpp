#include "otpch.h"

#include "Path.h"

#include <filesystem>
#include <string>

#ifdef _WIN32
#include <windows.h>
#endif

std::filesystem::path makePath(std::filesystem::path const& rel)
{
#ifdef _WIN32
    std::wstring buffer(MAX_PATH, L'\0');

    while (true) {
        SetLastError(ERROR_SUCCESS);
        const DWORD length = GetModuleFileNameW(nullptr, buffer.data(), static_cast<DWORD>(buffer.size()));
        if (length == 0) {
            return rel;
        }

        if (length < buffer.size()) {
            buffer.resize(length);
            std::filesystem::path exePath(buffer);
            return exePath.parent_path() / rel;
        }

        if (GetLastError() != ERROR_INSUFFICIENT_BUFFER) {
            buffer.resize(length);
            std::filesystem::path exePath(buffer);
            return exePath.parent_path() / rel;
        }

        buffer.resize(buffer.size() * 2, L'\0');
    }
#else
    return rel;
#endif
}
