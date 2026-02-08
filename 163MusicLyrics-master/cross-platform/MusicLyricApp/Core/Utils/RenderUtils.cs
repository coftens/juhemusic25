using System;
using System.Collections.Generic;
using System.Text;
using MusicLyricApp.Models;

namespace MusicLyricApp.Core.Utils;

public static class RenderUtils
{
    public static string RenderSearchResult(Dictionary<string, ResultVo<SaveVo>> resDict, Dictionary<string, SaveVo> successMap)
    {
        var log = new StringBuilder();

        foreach (var (songId, resultVo) in resDict)
        {
            log.Append($"{songId}");

            if (resultVo.IsSuccess())
            {
                var saveVo = resultVo.Data;
                successMap.Add(songId, saveVo);

                log.Append($" => {saveVo.SongVo.Name}");
            }

            log
                .Append($" => {resultVo.ErrorMsg}")
                .Append(Environment.NewLine);
        }

        log
            .Append(Environment.NewLine)
            .Append(
                $"累计 {resDict.Count} 成功 {successMap.Count} 失败 {resDict.Count - successMap.Count}")
            .Append(Environment.NewLine);
        
        return log.ToString();
    }
    
    public static string RenderStorageResult(Dictionary<string, string> skipRes, HashSet<string> successRes)
    {
        var log = new StringBuilder();

        if (successRes.Count > 0)
        {
            log
                .Append("保存成功：")
                .Append(Environment.NewLine);
            foreach (var e in successRes)
            {
                log
                    .Append(e)
                    .Append(Environment.NewLine);
            }
        }

        if (skipRes.Count > 0)
        {
            log
                .Append("保存失败：")
                .Append(Environment.NewLine);
            foreach (var pair in skipRes)
            {
                log
                    .Append($"{pair.Key} => {pair.Value}")
                    .Append(Environment.NewLine);
            }
        }
        
        return log.ToString();
    }
}